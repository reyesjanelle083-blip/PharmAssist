<?php

namespace App\Services\Feed;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Validator;

/**
 * Message Translator: a branch's inventory feed (XML or JSON) becomes one normalised JSON structure that
 * inventory-service understands:
 *
 *   {branch_id, generated_at, items: [{row, generic_name, brand_name, strength, dosage_form, quantity, price,
 *                                      batch_no, expiration_date, min_threshold}]}
 *
 * It only translates: no database writes and no outbound calls. Whether a medicine exists or a quantity is valid is
 * decided later, row by row, by inventory-service.
 *
 * The XML parser is hardened (see fromXml): untrusted input must never be able to read files, reach the network,
 * or blow up memory.
 */
class InventoryFeedTranslator
{
    public const MAX_ERRORS = 10;
    private const XML_TYPES = ['application/xml', 'text/xml'];

    /**
     * @return array{branch_id: int, generated_at: string, items: list<array<string, mixed>>}
     *
     * @throws FeedException 415 unsupported type, 413 too large, 400 malformed
     */
    public function translate(string $body, string $contentType): array
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));

        if (! in_array($type, [...self::XML_TYPES, 'application/json'], true)) {
            throw new FeedException('UNSUPPORTED_MEDIA_TYPE', 'The feed must be sent as application/xml, text/xml or application/json.', 415, 'unsupported_media_type');
        }

        $limit = (int) config('integration.feed.max_kb', 512) * 1024;
        if (strlen($body) > $limit) {
            throw new FeedException('PAYLOAD_TOO_LARGE', 'The feed is larger than '.config('integration.feed.max_kb', 512).' KB.', 413, 'too_large', in_array($type, self::XML_TYPES, true) ? 'translate_xml' : 'translate_json');
        }

        return in_array($type, self::XML_TYPES, true) ? $this->fromXml($body) : $this->fromJson($body);
    }

    // ------------------------------------------------------------------ XML

    /**
     * Hardening, in order:
     *  - UTF-8 only (other encodings could hide a DOCTYPE from the check below);
     *  - any DOCTYPE or ENTITY declaration is refused before parsing (no external entities, no entity expansion bombs);
     *  - LIBXML_NONET (never touch the network) and never LIBXML_NOENT (never substitute entities);
     *  - internal libxml errors only (nothing is printed, no server paths leak);
     *  - the parsed document must have no DTD, must not exceed the item limit, and must satisfy the XSD.
     */
    private function fromXml(string $body): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body; // a UTF-8 byte order mark is harmless

        if (trim($body) === '') { // DOMDocument::loadXML() throws on an empty string: answer 400, never a 500
            throw $this->malformedXml('malformed_xml', ['Line 1: The feed is empty.']);
        }

        if (! mb_check_encoding($body, 'UTF-8')) {
            throw $this->malformedXml('invalid_encoding', ['The feed must be UTF-8 encoded text.']);
        }
        if (preg_match('/^\s*<\?xml[^>]*\bencoding\s*=\s*["\']([^"\']+)["\']/i', $body, $m) && strtoupper($m[1]) !== 'UTF-8') {
            throw $this->malformedXml('invalid_encoding', ['The XML declaration must say encoding="UTF-8".']);
        }
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $body)) {
            throw $this->malformedXml('doctype_forbidden', ['DOCTYPE and ENTITY declarations are not allowed.']);
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $parsed = $dom->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS); // deliberately no LIBXML_NOENT, no DTD loading

            if (! $parsed) {
                throw $this->malformedXml('malformed_xml', $this->libxmlMessages());
            }
            if ($dom->doctype !== null) { // defence in depth: the pre-check should have caught it
                throw $this->malformedXml('doctype_forbidden', ['DOCTYPE and ENTITY declarations are not allowed.']);
            }

            $maxItems = (int) config('integration.feed.max_items', 500);
            if ($dom->getElementsByTagName('item')->length > $maxItems) {
                throw $this->malformedXml('too_many_items', ["A feed may contain at most {$maxItems} items."]);
            }

            if (! $dom->schemaValidate((string) config('integration.feed.xsd_path'))) {
                throw $this->malformedXml('schema_invalid', $this->libxmlMessages());
            }

            return $this->extractXml($dom);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array{branch_id: int, generated_at: string, items: list<array<string, mixed>>} */
    private function extractXml(DOMDocument $dom): array
    {
        $root = $dom->documentElement;
        $items = [];

        foreach ($dom->getElementsByTagName('item') as $index => $item) {
            $items[] = $this->normaliseItem($index + 1, [
                'generic_name' => $this->text($item, 'genericName'),
                'brand_name' => $this->text($item, 'brandName'),
                'strength' => $this->text($item, 'strength'),
                'dosage_form' => $this->text($item, 'dosageForm'),
                'quantity' => $this->text($item, 'quantity'),
                'price' => $this->text($item, 'price'),
                'batch_no' => $this->text($item, 'batchNo'),
                'expiration_date' => substr((string) $this->text($item, 'expirationDate'), 0, 10), // drops an optional time zone
                'min_threshold' => $this->text($item, 'minThreshold'),
            ]);
        }

        return [
            'branch_id' => (int) $root->getAttribute('branchId'),
            'generated_at' => trim($root->getAttribute('generatedAt')),
            'items' => $items,
        ];
    }

    private function text(DOMElement $item, string $name): ?string
    {
        $node = $item->getElementsByTagName($name)->item(0);

        return $node === null ? null : trim($node->textContent);
    }

    /** @return list<string> */
    private function libxmlMessages(): array
    {
        $messages = [];

        foreach (libxml_get_errors() as $error) {
            if (count($messages) >= self::MAX_ERRORS) {
                break;
            }

            // Messages describe the XML (element names, values); they never contain server file paths. Scrub anyway.
            $text = trim(preg_replace('/\s+/', ' ', $error->message) ?? '');
            $text = str_replace([base_path(), str_replace('/', '\\', base_path()), resource_path(), str_replace('/', '\\', resource_path())], '[app]', $text);
            $messages[] = 'Line '.max(1, $error->line).': '.mb_substr($text, 0, 200);
        }

        return $messages ?: ['The feed is not valid XML.'];
    }

    /** @param list<string> $errors */
    private function malformedXml(string $reason, array $errors): FeedException
    {
        return new FeedException(
            'MALFORMED_XML',
            'The feed is not a valid inventory feed.',
            400,
            $reason,
            'translate_xml',
            ['errors' => array_slice($errors, 0, self::MAX_ERRORS)],
        );
    }

    // ------------------------------------------------------------------ JSON

    private function fromJson(string $body): array
    {
        $data = json_decode($body, true, 16);

        if (! is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw $this->malformedJson('malformed_json', ['The body is not valid JSON.']);
        }

        $validator = Validator::make($data, [
            'branch_id' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'generated_at' => ['required', 'string', 'max:40', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:'.(int) config('integration.feed.max_items', 500)],
            'items.*' => ['array'],
            'items.*.generic_name' => ['required', 'string', 'max:150'],
            'items.*.brand_name' => ['present', 'nullable', 'string', 'max:100'],
            'items.*.strength' => ['required', 'string', 'max:50'],
            'items.*.dosage_form' => ['required', 'string', 'max:50'],
            'items.*.quantity' => ['required', fn ($attr, $value, $fail) => is_scalar($value) && ! is_bool($value) && strlen((string) $value) <= 20 ? null : $fail("The {$attr} field must be a short number.")],
            'items.*.price' => ['required', fn ($attr, $value, $fail) => is_scalar($value) && ! is_bool($value) && strlen((string) $value) <= 20 ? null : $fail("The {$attr} field must be a short number.")],
            'items.*.batch_no' => ['present', 'nullable', 'string', 'max:50'],
            'items.*.expiration_date' => ['required', 'date_format:Y-m-d'],
            'items.*.min_threshold' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            throw $this->malformedJson('schema_invalid', array_map(
                fn (string $m) => mb_substr($m, 0, 200),
                array_slice($validator->errors()->all(), 0, self::MAX_ERRORS),
            ));
        }

        $items = [];
        foreach (array_values($data['items']) as $index => $item) {
            $items[] = $this->normaliseItem($index + 1, $item);
        }

        return [
            'branch_id' => (int) $data['branch_id'],
            'generated_at' => trim((string) $data['generated_at']),
            'items' => $items,
        ];
    }

    /** @param list<string> $errors */
    private function malformedJson(string $reason, array $errors): FeedException
    {
        return new FeedException('MALFORMED_JSON', 'The feed is not a valid inventory feed.', 400, $reason, 'translate_json', ['errors' => $errors]);
    }

    // ------------------------------------------------------------------ shared

    /**
     * One output shape for both input formats, so the same data gives the same result whichever format carried it.
     * Values are NOT judged here (a quantity of -5 or "abc" is passed on as it is): inventory-service rejects the row
     * with a code, without losing the other rows.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normaliseItem(int $row, array $raw): array
    {
        $batch = isset($raw['batch_no']) ? trim((string) $raw['batch_no']) : '';

        return [
            'row' => $row,
            'generic_name' => trim((string) ($raw['generic_name'] ?? '')),
            'brand_name' => trim((string) ($raw['brand_name'] ?? '')),
            'strength' => trim((string) ($raw['strength'] ?? '')),
            'dosage_form' => trim((string) ($raw['dosage_form'] ?? '')),
            'quantity' => $this->number($raw['quantity'] ?? null),
            'price' => isset($raw['price']) ? trim((string) $raw['price']) : '',
            'batch_no' => $batch === '' ? null : $batch,
            'expiration_date' => isset($raw['expiration_date']) ? trim((string) $raw['expiration_date']) : '',
            'min_threshold' => ($raw['min_threshold'] ?? null) === null || $raw['min_threshold'] === '' ? null : (int) $raw['min_threshold'],
        ];
    }

    /** A whole number stays an integer (same in XML text and JSON); anything else is passed on as text to be rejected later. */
    private function number(mixed $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }

        $text = trim((string) $value);

        return preg_match('/^-?\d{1,15}$/', $text) ? (int) $text : $text;
    }
}
