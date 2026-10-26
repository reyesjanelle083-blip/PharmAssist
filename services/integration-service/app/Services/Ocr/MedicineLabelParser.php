<?php

namespace App\Services\Ocr;

class MedicineLabelParser
{
    /**
     * Extract useful medicine-label fields from OCR text.
     *
     * "dosage" here means the printed medicine strength/dose,
     * e.g. "500 mg" or "250 mg/5 mL".
     *
     * @return array{
     *   medicine_name: ?string,
     *   dosage: ?string,
     *   batch_no: ?string,
     *   expiration_date: ?string,
     *   warnings: list<string>
     * }
     */
    public function parse(?string $text): array
    {
        $text = trim((string) $text);

        if ($text === '') {
            return [
                'medicine_name' => null,
                'dosage' => null,
                'batch_no' => null,
                'expiration_date' => null,
                'warnings' => [
                    'MEDICINE_NAME_NOT_FOUND',
                    'DOSAGE_NOT_FOUND',
                    'BATCH_NO_NOT_FOUND',
                    'EXPIRATION_DATE_NOT_FOUND',
                ],
            ];
        }

        $lines = array_values(array_filter(
            array_map(
                fn (string $line) => trim(preg_replace('/\s+/u', ' ', $line) ?? ''),
                preg_split('/\R/u', $text) ?: [],
            ),
            fn (string $line) => $line !== '',
        ));

        $dosage = $this->extractDosage($text);
        $batch = $this->extractBatch($text);
        $expiration = $this->extractExpiration($text);
        $name = $this->extractMedicineName($lines, $dosage);

        $warnings = [];

        if ($name === null) {
            $warnings[] = 'MEDICINE_NAME_NOT_FOUND';
        }

        if ($dosage === null) {
            $warnings[] = 'DOSAGE_NOT_FOUND';
        }

        if ($batch === null) {
            $warnings[] = 'BATCH_NO_NOT_FOUND';
        }

        if ($expiration === null) {
            $warnings[] = 'EXPIRATION_DATE_NOT_FOUND';
        }

        return [
            'medicine_name' => $name,
            'dosage' => $dosage,
            'batch_no' => $batch,
            'expiration_date' => $expiration,
            'warnings' => $warnings,
        ];
    }

    private function extractDosage(string $text): ?string
    {
        $patterns = [
            // 250 mg / 5 mL
            '/\b\d+(?:\.\d+)?\s*(?:mg|mcg|µg|g)\s*\/\s*\d+(?:\.\d+)?\s*(?:mL|ml|L)\b/ui',

            // 10 mg/2 mg/500 mg
            '/\b\d+(?:\.\d+)?\s*(?:mg|mcg|µg|g)(?:\s*\/\s*\d+(?:\.\d+)?\s*(?:mg|mcg|µg|g)){1,4}\b/ui',

            // 500 mg, 5 mL, 10 mcg, 100 IU
            '/\b\d+(?:\.\d+)?\s*(?:mg|mcg|µg|g|mL|ml|L|IU|units?)\b/ui',

            // 1%, 2.5%
            '/\b\d+(?:\.\d+)?\s*%\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                return $this->cleanValue($match[0]);
            }
        }

        return null;
    }

    private function extractBatch(string $text): ?string
    {
        $patterns = [
            '/\b(?:BATCH|LOT)\s*(?:NO\.?|NUMBER|#)?\s*[:#\-]?\s*([A-Z0-9][A-Z0-9\-\/]{2,30})\b/iu',
            '/\b(?:B\.?\s*NO\.?)\s*[:#\-]?\s*([A-Z0-9][A-Z0-9\-\/]{2,30})\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                $value = strtoupper($this->cleanValue($match[1]));

                if (! in_array($value, ['NO', 'NUMBER', 'LOT', 'BATCH'], true)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function extractExpiration(string $text): ?string
    {
        $label =
            '(?:EXP(?:IRY|IRATION)?(?:\s*DATE)?|USE\s*BY|BEST\s*BEFORE)';

        $datePatterns = [
            // EXP 08/2028, 08-2028, 08.2028
            "/\b{$label}\s*[:#\-]?\s*(\d{1,2}[\/\-.]\d{4})\b/iu",

            // EXP 08/28
            "/\b{$label}\s*[:#\-]?\s*(\d{1,2}[\/\-.]\d{2})\b/iu",

            // EXP 2028-08 or 2028/08
            "/\b{$label}\s*[:#\-]?\s*(\d{4}[\/\-.]\d{1,2})\b/iu",

            // EXP AUG 2028 / AUGUST 2028
            "/\b{$label}\s*[:#\-]?\s*((?:JAN(?:UARY)?|FEB(?:RUARY)?|MAR(?:CH)?|APR(?:IL)?|MAY|JUN(?:E)?|JUL(?:Y)?|AUG(?:UST)?|SEP(?:TEMBER)?|OCT(?:OBER)?|NOV(?:EMBER)?|DEC(?:EMBER)?)\s+\d{4})\b/iu",

            // EXP 2028-08-31
            "/\b{$label}\s*[:#\-]?\s*(\d{4}[\/\-.]\d{1,2}[\/\-.]\d{1,2})\b/iu",
        ];

        foreach ($datePatterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                return strtoupper($this->cleanValue($match[1]));
            }
        }

        return null;
    }

    /**
     * Conservative heuristic:
     * choose a likely product/generic-name line near the top of the package
     * while rejecting packaging warnings, dates, batch lines and regulatory text.
     */
    private function extractMedicineName(array $lines, ?string $dosage): ?string
    {
        $candidates = [];

        foreach (array_slice($lines, 0, 12) as $index => $line) {
            if ($this->isNoiseLine($line)) {
                continue;
            }

            $candidate = $line;

            if ($dosage !== null) {
                $candidate = str_ireplace($dosage, '', $candidate);
            }

            $candidate = preg_replace(
                '/\b(?:tablets?|capsules?|caplets?|syrup|suspension|solution|cream|ointment|drops?|injection|powder)\b/iu',
                '',
                $candidate,
            ) ?? $candidate;

            $candidate = trim(
                preg_replace('/\s+/u', ' ', $candidate) ?? '',
                " \t\n\r\0\x0B-–—,:;()[]",
            );

            if (
                mb_strlen($candidate) < 3 ||
                mb_strlen($candidate) > 80 ||
                ! preg_match('/\p{L}/u', $candidate)
            ) {
                continue;
            }

            $letters = preg_replace('/[^\p{L}]/u', '', $candidate) ?? '';
            $letterCount = max(1, mb_strlen($letters));

            preg_match_all('/\p{Lu}/u', $candidate, $uppercase);
            $uppercaseRatio = count($uppercase[0]) / $letterCount;

            $score = 100 - ($index * 5);

            if ($uppercaseRatio >= 0.5) {
                $score += 15;
            }

            if (preg_match('/^[\p{L}][\p{L}\s+\-]+$/u', $candidate)) {
                $score += 10;
            }

            $candidates[] = [
                'value' => $candidate,
                'score' => $score,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            fn (array $a, array $b) => $b['score'] <=> $a['score'],
        );

        return $candidates[0]['value'];
    }

    private function isNoiseLine(string $line): bool
    {
        $patterns = [
            '/\b(?:BATCH|LOT)\b/iu',
            '/\bEXP(?:IRY|IRATION)?\b/iu',
            '/\bMFG\b/iu',
            '/\bMANUFACTURED\b/iu',
            '/\bDISTRIBUTED\b/iu',
            '/\bREGISTERED\b/iu',
            '/\bREG\.?\s*NO\b/iu',
            '/\bFDA\b/iu',
            '/\bDR\.?\s*NO\b/iu',
            '/\bSTORE\b/iu',
            '/\bKEEP\s+OUT\b/iu',
            '/\bKEEP\s+AWAY\b/iu',
            '/\bDO\s+NOT\b/iu',
            '/\bSEAL\b/iu',
            '/\bPRESCRIPTION\b/iu',
            '/\bRX\s+ONLY\b/iu',
            '/\bFOR\s+EXTERNAL\s+USE\b/iu',
            '/\bBARCODE\b/iu',
            '/^\s*\d[\d\s\/.\-]+\s*$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    private function cleanValue(string $value): string
    {
        return trim(
            preg_replace('/\s+/u', ' ', $value) ?? '',
            " \t\n\r\0\x0B,;:",
        );
    }
}