<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Medicine;
use App\Models\StockAlert;
use App\Models\StockMovement;
use Carbon\Carbon;
use Database\Seeders\Concerns\ReadsCsv;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Per-branch price and stock, loaded from database/data/inventory_branch_1.csv and inventory_branch_2.csv.
 * Medicines are found by the natural key (generic_name, brand_name, strength, dosage_form); this seeder never creates one, and a row
 * whose medicine is missing stops the run with a message naming the file, line and medicine (run MedicineSeeder first).
 *
 * Re-runnable: rows are matched on (branch, medicine). A new row gets reserved_quantity 0, an initial stock_movements entry and,
 * when low or empty, an open stock_alerts entry. An existing row has its price, stock, threshold, batch, expiry and status refreshed
 * from the file, but its reserved quantity (stock held for reservations) is never touched. Everything is validated before anything
 * is written, and written in one transaction.
 */
class BranchInventorySeeder extends Seeder
{
    use ReadsCsv;
    use RefusesProduction;

    private const FILES = ['inventory_branch_1.csv', 'inventory_branch_2.csv'];

    public function run(): void
    {
        $this->refuseInProduction();

        $items = [];
        $seen = [];
        foreach (self::FILES as $file) {
            foreach ($this->readCsv($this->dataPath($file)) as $row) {
                $item = $this->item($file, $row);

                $identity = $item['branch']->id.'|'.$item['medicine']->id;
                if (isset($seen[$identity])) {
                    throw new RuntimeException("{$item['where']}: {$item['label']} is listed twice for branch {$item['branch']->id} (first at {$seen[$identity]}).");
                }
                $seen[$identity] = $item['where'];

                $items[] = $item;
            }
        }

        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                $this->store($item);
            }
        });
    }

    /**
     * @param array<string, string|int> $row
     * @return array{where: string, label: string, branch: Branch, medicine: Medicine, price: string, stock: int, threshold: int, batch_no: ?string, expiration_date: string}
     */
    private function item(string $file, array $row): array
    {
        $where = "{$file} line {$row['_line']}";
        $label = "'{$row['generic_name']}' / brand '{$row['brand_name']}' / {$row['strength']} / {$row['dosage_form']}";

        if (! ctype_digit($row['branch_id'])) {
            throw new RuntimeException("{$where}: branch_id must be a whole number, found '{$row['branch_id']}'.");
        }
        $branch = Branch::find((int) $row['branch_id'])
            ?? throw new RuntimeException("{$where}: branch {$row['branch_id']} not found. Run BranchSeeder first.");

        $medicine = Medicine::where([
            'generic_name' => $row['generic_name'],
            'brand_name' => $row['brand_name'], // blank means unbranded, stored as ''
            'strength' => $row['strength'],
            'dosage_form' => $row['dosage_form'],
        ])->first() ?? throw new RuntimeException("{$where}: medicine not found: {$label}. Run MedicineSeeder first; this seeder does not create medicines.");

        foreach (['quantity', 'min_threshold'] as $number) {
            if (! ctype_digit($row[$number])) {
                throw new RuntimeException("{$where}: {$number} must be a whole number of 0 or more, found '{$row[$number]}'.");
            }
        }
        if (! is_numeric($row['price']) || (float) $row['price'] < 0) {
            throw new RuntimeException("{$where}: price must be a number of 0 or more, found '{$row['price']}'.");
        }

        if (mb_strlen($row['batch_no']) > 50) {
            throw new RuntimeException("{$where}: batch_no is ".mb_strlen($row['batch_no']).' characters but the column holds at most 50.');
        }

        $expiry = $row['expiration_date'];
        if ($expiry !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) || Carbon::createFromFormat('!Y-m-d', $expiry)->format('Y-m-d') !== $expiry)) {
            throw new RuntimeException("{$where}: expiration_date must be YYYY-MM-DD, found '{$expiry}'.");
        }

        return [
            'where' => $where,
            'label' => $label,
            'branch' => $branch,
            'medicine' => $medicine,
            'price' => number_format((float) $row['price'], 2, '.', ''),
            'stock' => (int) $row['quantity'],
            'threshold' => (int) $row['min_threshold'],
            'batch_no' => $row['batch_no'] === '' ? null : $row['batch_no'],
            'expiration_date' => $expiry === '' ? null : $expiry,
        ];
    }

    /** @param array<string, mixed> $item */
    private function store(array $item): void
    {
        $inventory = BranchInventory::firstOrNew(['branch_id' => $item['branch']->id, 'medicine_id' => $item['medicine']->id]);
        $status = BranchInventory::statusFor($item['stock'], $item['threshold']);

        $values = [
            'price' => $item['price'],
            'stock_quantity' => $item['stock'],
            'min_threshold' => $item['threshold'],
            'batch_no' => $item['batch_no'],
            'expiration_date' => $item['expiration_date'],
            'status' => $status,
        ];

        if ($inventory->exists) {
            // The database allows reserved_quantity <= stock_quantity; say so clearly rather than failing on the constraint.
            if ($inventory->reserved_quantity > $item['stock']) {
                throw new RuntimeException("{$item['where']}: {$item['label']} has {$inventory->reserved_quantity} reserved but the file says quantity {$item['stock']}. Release or complete those reservations first.");
            }

            $inventory->fill($values)->save(); // reserved_quantity is deliberately not in $values

            return;
        }

        $inventory->fill($values + ['reserved_quantity' => 0, 'version' => 1])->save();

        StockMovement::create([
            'branch_inventory_id' => $inventory->id,
            'delta' => $item['stock'],
            'reason' => 'manual',
            'actor_id' => null,
            'request_id' => 'seed',
        ]);

        if ($status !== 'available') {
            StockAlert::create([
                'branch_inventory_id' => $inventory->id,
                'alert_type' => $status === 'out_of_stock' ? 'out_of_stock' : 'low_stock',
            ]);
        }
    }
}
