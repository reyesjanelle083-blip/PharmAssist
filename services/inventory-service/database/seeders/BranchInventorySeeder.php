<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Medicine;
use App\Models\StockAlert;
use App\Models\StockMovement;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Per-branch price and stock for the demo medicines. Prices and quantities are made up for demos.
 *
 * Re-runnable and safe: a row is only created when (branch, medicine) does not exist yet.
 * Existing rows are never overwritten, so stock that has since changed or been reserved is kept.
 * Each new row also gets an initial stock_movements entry and, when low or empty, an open stock_alerts entry.
 */
class BranchInventorySeeder extends Seeder
{
    use RefusesProduction;

    /** Fixed future dates (the seed data is for the 2026 demo). */
    private const EXPIRY_DATES = ['2027-06-30', '2027-12-31', '2028-03-31', '2028-09-30'];

    /**
     * branch id => medicine code => [price, stock_quantity, min_threshold].
     * Mix: above threshold (available), below threshold (low_stock) and 0 (out_of_stock).
     *
     * @return array<int, array<string, array{0: string, 1: int, 2: int}>>
     */
    public static function stock(): array
    {
        return [
            1 => [ // Ro-Eful Pharmacy
                'amox500' => ['10.00', 40, 20],
                'neozep' => ['7.00', 8, 15],          // low_stock
                'paracetamol500' => ['3.50', 120, 30],
                'ibuprofen200' => ['9.00', 0, 20],    // out_of_stock
                'cetirizine10' => ['6.50', 60, 20],
                'losartan50' => ['5.00', 14, 25],     // low_stock
                'metformin500' => ['2.50', 90, 30],
                'vitc500' => ['4.00', 200, 50],
                'loperamide2' => ['5.50', 35, 15],
            ],
            2 => [ // The Generics Pharmacy
                'mefenamic500' => ['3.00', 100, 25],
                'losartan50' => ['4.75', 150, 40],
                'ibuprofen200' => ['9.00', 125, 30],
                'telmisartan80' => ['15.00', 155, 40],
                'amox500' => ['8.50', 70, 25],
                'azithro500' => ['28.00', 12, 15],    // low_stock
                'paracetamol500' => ['3.00', 300, 60],
                'amlodipine5' => ['4.25', 110, 30],
                'atorva20' => ['9.00', 9, 20],        // low_stock
                'metformin500' => ['2.25', 130, 40],
                'omeprazole20' => ['6.75', 55, 20],
                'salbutamol' => ['245.00', 6, 8],     // low_stock
                'cetirizine10' => ['5.50', 80, 25],
            ],
            3 => [ // The Generics Pharmacy - Novaliches Branch
                'amox500' => ['9.50', 55, 20],
                'paracetamol500' => ['3.25', 150, 40],
                'neozep' => ['7.50', 45, 15],
                'mefenamic500' => ['3.25', 30, 25],
                'losartan50' => ['5.25', 0, 25],      // out_of_stock
                'telmisartan80' => ['16.00', 25, 30], // low_stock
                'ibuprofen200' => ['9.25', 90, 30],
                'cetirizine10' => ['6.00', 22, 20],
                'loperamide2' => ['5.75', 40, 15],
                'vitc500' => ['4.25', 150, 40],
                'omeprazole20' => ['7.00', 30, 20],
            ],
        ];
    }

    public function run(): void
    {
        $this->refuseInProduction();

        $catalog = MedicineSeeder::medicines();

        foreach (self::stock() as $branchId => $items) {
            $branch = Branch::find($branchId)
                ?? throw new RuntimeException("Branch {$branchId} not found. Run BranchSeeder first.");

            $position = 0;
            foreach ($items as $code => [$price, $stock, $threshold]) {
                $position++;
                $row = $catalog[$code] ?? throw new RuntimeException("Unknown medicine code '{$code}'.");

                $medicine = Medicine::where([
                    'generic_name' => $row['generic_name'],
                    'brand_name' => $row['brand_name'],
                    'strength' => $row['strength'],
                    'dosage_form' => $row['dosage_form'],
                ])->first() ?? throw new RuntimeException("Medicine '{$code}' not found. Run MedicineSeeder first.");

                $inventory = BranchInventory::firstOrNew(['branch_id' => $branch->id, 'medicine_id' => $medicine->id]);
                if ($inventory->exists) {
                    continue; // keep current stock, reservations and history
                }

                DB::transaction(function () use ($inventory, $price, $stock, $threshold, $code, $branchId, $position) {
                    $inventory->fill([
                        'price' => $price,
                        'stock_quantity' => $stock,
                        'reserved_quantity' => 0,
                        'min_threshold' => $threshold,
                        'batch_no' => sprintf('B26-%s-%02d', strtoupper(substr($code, 0, 4)), $branchId),
                        'expiration_date' => self::EXPIRY_DATES[$position % count(self::EXPIRY_DATES)],
                        'status' => BranchInventory::statusFor($stock, $threshold),
                        'version' => 1,
                    ])->save();

                    StockMovement::create([
                        'branch_inventory_id' => $inventory->id,
                        'delta' => $stock,
                        'reason' => 'manual',
                        'actor_id' => null,
                        'request_id' => 'seed',
                    ]);

                    if ($inventory->status !== 'available') {
                        StockAlert::create([
                            'branch_inventory_id' => $inventory->id,
                            'alert_type' => $inventory->status === 'out_of_stock' ? 'out_of_stock' : 'low_stock',
                        ]);
                    }
                });
            }
        }
    }
}
