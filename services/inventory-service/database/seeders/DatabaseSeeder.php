<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use RefusesProduction;

    public function run(): void
    {
        $this->refuseInProduction();

        // Order matters: inventory rows reference branches and medicines.
        $this->call([
            BranchSeeder::class,
            MedicineSeeder::class,
            BranchInventorySeeder::class,
        ]);
    }
}
