<?php

namespace Database\Seeders;

use App\Models\Medicine;
use Database\Seeders\Concerns\ReadsCsv;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The medicine catalog, one row per medicine (stock and price live in branch_inventory), loaded from
 * database/data/medicines_master.csv. Counseling texts are short, general, plain-language reminders, not medical advice.
 * Re-runnable: matched by the table's unique key (generic_name, brand_name, strength, dosage_form); the other columns are updated.
 * All rows are validated first and written in one transaction, so a bad row changes nothing.
 */
class MedicineSeeder extends Seeder
{
    use ReadsCsv;
    use RefusesProduction;

    private const FILE = 'medicines_master.csv';

    private const MAX_LENGTH = ['generic_name' => 150, 'brand_name' => 100, 'category' => 100, 'strength' => 50, 'dosage_form' => 50, 'image_path' => 255];

    public function run(): void
    {
        $this->refuseInProduction();

        $rows = $this->readCsv($this->dataPath(self::FILE));
        $medicines = array_map(fn (array $row) => $this->medicine($row), $rows);

        DB::transaction(function () use ($medicines) {
            foreach ($medicines as $medicine) {
                Medicine::updateOrCreate($medicine['key'], $medicine['values']);
            }
        });
    }

    /**
     * @param array<string, string|int> $row
     * @return array{key: array<string, string>, values: array<string, mixed>}
     */
    private function medicine(array $row): array
    {
        $where = self::FILE." line {$row['_line']}";

        foreach (['generic_name', 'category', 'strength', 'dosage_form'] as $required) {
            if (($row[$required] ?? '') === '') {
                throw new RuntimeException("{$where}: '{$required}' is empty.");
            }
        }

        // The widths of the medicines table (see the create_medicines migration): say so plainly instead of a raw SQL error.
        foreach (self::MAX_LENGTH as $column => $max) {
            if (mb_strlen($row[$column]) > $max) {
                throw new RuntimeException("{$where}: '{$column}' is ".mb_strlen($row[$column])." characters but the column holds at most {$max}: {$row[$column]}");
            }
        }

        return [
            'key' => [
                'generic_name' => $row['generic_name'],
                'brand_name' => $row['brand_name'], // blank means unbranded and is stored as '' (the column is not nullable)
                'strength' => $row['strength'],
                'dosage_form' => $row['dosage_form'],
            ],
            'values' => [
                'category' => $row['category'],
                'prescription_required' => $this->boolean($row['prescription_required'], $where),
                'image_path' => $row['image_path'] === '' ? null : $row['image_path'],
                'counseling_summary' => $row['counseling_summary'] === '' ? null : $row['counseling_summary'],
            ],
        ];
    }

    private function boolean(string $value, string $where): bool
    {
        return match (strtolower($value)) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw new RuntimeException("{$where}: prescription_required must be true or false, found '{$value}'."),
        };
    }
}
