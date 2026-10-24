<?php

namespace Database\Seeders;

use App\Models\Branch;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo branches. Branch id 1 is referenced by the seeded staff accounts in identity-service.
 * Run with: php artisan db:seed --class=BranchSeeder (re-runnable: matched by unique name).
 */
class BranchSeeder extends Seeder
{
    use RefusesProduction;

    /**
     * Branches 1 and 2 come from the previous PharmAssist database.
     * Branch 3 is the Novaliches branch mentioned in the project papers; only its area is known,
     * so its street address is left out.
     *
     * @return list<array{id: int, name: string, address: string, latitude: float, longitude: float}>
     */
    public static function branches(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Ro-Eful Pharmacy',
                'address' => '560 A. Tagaytay St., Kaloocan City',
                'latitude' => 14.6507, // approximate, verify before demo
                'longitude' => 120.9830, // approximate, verify before demo
            ],
            [
                'id' => 2,
                'name' => 'The Generics Pharmacy',
                'address' => '2520 J. Posadas St., Brgy. 902, Zone 100, District VI, Manila City',
                'latitude' => 14.5830, // approximate, verify before demo
                'longitude' => 121.0050, // approximate, verify before demo
            ],
            [
                'id' => 3,
                'name' => 'The Generics Pharmacy - Novaliches Branch',
                'address' => 'Novaliches, Quezon City',
                'latitude' => 14.7230, // approximate, verify before demo
                'longitude' => 121.0370, // approximate, verify before demo
            ],
        ];
    }

    public function run(): void
    {
        $this->refuseInProduction();

        foreach (self::branches() as $row) {
            $branch = Branch::firstOrNew(['name' => $row['name']]);

            if (! $branch->exists) {
                if (Branch::whereKey($row['id'])->exists()) {
                    throw new RuntimeException("Branch id {$row['id']} is already used by a different branch; cannot seed '{$row['name']}'.");
                }
                $branch->id = $row['id'];
            }

            $branch->fill([
                'address' => $row['address'],
                'latitude' => $row['latitude'],
                'longitude' => $row['longitude'],
                'is_active' => true,
            ])->save();
        }
    }
}
