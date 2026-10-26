<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

trait ReadsCsv
{
    /**
     * Reads a CSV file with a header row into rows keyed by the header names. Values are trimmed. Blank lines are skipped.
     * Every row carries its file line number under '_line' so errors can point at the exact row.
     *
     * @return list<array<string, string|int>>
     *
     * @throws RuntimeException when the file is missing, has no header, or a row has the wrong number of columns
     */
    protected function readCsv(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Seed data file not found: {$path}");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Seed data file could not be opened: {$path}");
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header === false || $header === [null]) {
                throw new RuntimeException("Seed data file has no header row: {$path}");
            }
            $header = array_map(fn ($name) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $name)), $header);

            $rows = [];
            $line = 1;
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if ($values === [null]) {
                    continue; // a blank line
                }
                if (count($values) !== count($header)) {
                    throw new RuntimeException(basename($path)." line {$line}: expected ".count($header).' columns but found '.count($values).'.');
                }
                $rows[] = array_combine($header, array_map(fn ($value) => trim((string) $value), $values)) + ['_line' => $line];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** The shared database/data folder the seed CSV files live in. */
    protected function dataPath(string $file): string
    {
        return database_path('data/'.$file);
    }
}
