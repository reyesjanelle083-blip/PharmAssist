<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Medicine;
use App\Models\StockAlert;
use App\Models\StockMovement;
use Database\Seeders\BranchInventorySeeder;
use Database\Seeders\BranchSeeder;
use Database\Seeders\MedicineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The CSV-backed seeders. Everything here runs on the in-memory SQLite database from phpunit.xml (RefreshDatabase), never the
 * development database. Tests of bad files point Laravel's database path at a temporary folder holding their own small CSV files.
 */
class SeedersTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_HEADER = 'generic_name,brand_name,category,strength,dosage_form,prescription_required,image_path,counseling_summary';

    private const INVENTORY_HEADER = 'branch_id,generic_name,brand_name,strength,dosage_form,quantity,price,batch_no,expiration_date,min_threshold';

    /** @var list<string> */
    private array $tempFolders = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFolders as $folder) {
            foreach (glob($folder.'/data/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($folder.'/data');
            @rmdir($folder);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------ helpers

    private function seedReal(): void
    {
        $this->seed([BranchSeeder::class, MedicineSeeder::class, BranchInventorySeeder::class]);
    }

    /** Points the seeders at a temporary database folder whose data/ holds exactly these files (name => contents). */
    private function useCsv(array $files): void
    {
        $folder = sys_get_temp_dir().'/pharmassist-seed-'.bin2hex(random_bytes(6));
        mkdir($folder.'/data', 0777, true);
        $this->tempFolders[] = $folder;

        foreach ($files as $name => $contents) {
            file_put_contents($folder.'/data/'.$name, $contents);
        }

        $this->app->useDatabasePath($folder);
    }

    private function amoxicillin(string $overrides = ''): string
    {
        return 'Amoxicillin,,Antibiotics,500mg,Capsule,'.($overrides ?: 'true').',,';
    }

    /** @return array<string, string> a valid pair of small files; $inventory1 is the body of inventory_branch_1.csv */
    private function smallFiles(string $inventory1 = '', string $master = ''): array
    {
        return [
            'medicines_master.csv' => self::MASTER_HEADER."\n".($master ?: $this->amoxicillin())."\n",
            'inventory_branch_1.csv' => self::INVENTORY_HEADER."\n".$inventory1,
            'inventory_branch_2.csv' => self::INVENTORY_HEADER."\n",
        ];
    }

    /** Runs a seeder that must fail and returns the message, so each test can check it names the problem. */
    private function failure(string $seeder): string
    {
        try {
            $this->seed($seeder);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        $this->fail("{$seeder} was expected to fail but it finished.");
    }

    private function row(int $branch, string $generic, string $brand, string $strength, string $form): BranchInventory
    {
        return BranchInventory::where('branch_id', $branch)->whereHas('medicine', fn ($q) => $q->where([
            'generic_name' => $generic, 'brand_name' => $brand, 'strength' => $strength, 'dosage_form' => $form,
        ]))->firstOrFail();
    }

    // ------------------------------------------------------------ the real CSV files

    public function test_the_medicine_seeder_loads_every_medicine_in_the_master_csv(): void
    {
        $this->seed([MedicineSeeder::class]);

        $this->assertSame(111, Medicine::count());

        $fluimucil = Medicine::where(['generic_name' => 'Acetylcysteine', 'brand_name' => 'Fluimucil', 'strength' => '600mg', 'dosage_form' => 'Effervescent Tablet'])->firstOrFail();
        $this->assertSame('Respiratory', $fluimucil->category);
        $this->assertFalse($fluimucil->prescription_required);
        $this->assertSame('/images/medicines/products/Fluimucil.avif', $fluimucil->image_path);
        $this->assertNull($fluimucil->counseling_summary, 'a blank counseling_summary is stored as null');

        $acyclovir = Medicine::where(['generic_name' => 'Acyclovir', 'brand_name' => 'Xycloviraxforte'])->firstOrFail();
        $this->assertTrue($acyclovir->prescription_required, '"true" becomes a real boolean');
        $this->assertNull($acyclovir->image_path, 'a blank image_path is stored as null');
    }

    public function test_the_inventory_seeder_loads_31_rows_for_branch_1_and_82_for_branch_2(): void
    {
        $this->seedReal();

        $this->assertSame(31, BranchInventory::where('branch_id', 1)->count());
        $this->assertSame(82, BranchInventory::where('branch_id', 2)->count());
        $this->assertSame(0, BranchInventory::where('branch_id', 3)->count(), 'the CSV files do not mention branch 3');

        $ceelin = $this->row(1, 'Ascorbic Acid + Zinc', 'Ceelin Plus', '100mg/10mg per 5mL, 120mL', 'Syrup');
        $this->assertSame([2, 0, 10, '151.19', 'L031368', '2027-04-30', 'low_stock', 1], [
            $ceelin->stock_quantity, $ceelin->reserved_quantity, $ceelin->min_threshold, $ceelin->price, $ceelin->batch_no,
            $ceelin->expiration_date->toDateString(), $ceelin->status, $ceelin->version,
        ]);

        $this->assertNull($this->row(1, 'Clonidine', 'Catapres', '75mcg', 'Tablet')->batch_no, 'a blank batch_no is stored as null');
        $this->assertSame(0, BranchInventory::where('reserved_quantity', '!=', 0)->count(), 'new rows start with nothing reserved');
    }

    public function test_every_new_inventory_row_gets_one_stock_movement_and_low_or_empty_rows_get_one_alert(): void
    {
        $this->seedReal();

        $rows = BranchInventory::count();
        $this->assertSame(113, $rows);
        $this->assertSame($rows, StockMovement::count());
        $this->assertSame((int) BranchInventory::sum('stock_quantity'), (int) StockMovement::sum('delta'), 'each movement records the starting stock of its row');

        $needAlert = BranchInventory::where('status', '!=', 'available')->count();
        $this->assertGreaterThan(0, $needAlert);
        $this->assertSame($needAlert, StockAlert::count());

        $ceelin = $this->row(1, 'Ascorbic Acid + Zinc', 'Ceelin Plus', '100mg/10mg per 5mL, 120mL', 'Syrup');
        $this->assertSame(2, StockMovement::where('branch_inventory_id', $ceelin->id)->value('delta'));
        $this->assertSame('low_stock', StockAlert::where('branch_inventory_id', $ceelin->id)->value('alert_type'));
    }

    public function test_running_the_seeders_again_creates_no_duplicates(): void
    {
        $this->seedReal();
        $before = [Medicine::count(), BranchInventory::count(), StockMovement::count(), StockAlert::count()];

        $this->seedReal();

        $this->assertSame([111, 113, 113, $before[3]], [Medicine::count(), BranchInventory::count(), StockMovement::count(), StockAlert::count()]);
        $this->assertSame($before, [Medicine::count(), BranchInventory::count(), StockMovement::count(), StockAlert::count()]);
        $this->assertSame(1, Branch::where('id', 1)->count());
    }

    public function test_reseeding_refreshes_stock_but_keeps_reserved_quantity_and_unrelated_rows(): void
    {
        $this->seedReal();
        $ceelin = $this->row(1, 'Ascorbic Acid + Zinc', 'Ceelin Plus', '100mg/10mg per 5mL, 120mL', 'Syrup');
        $ceelin->update(['stock_quantity' => 50, 'reserved_quantity' => 2, 'price' => '999.00']);

        // an older demo row that is in no CSV file stays exactly as it is
        $demo = Medicine::create(['generic_name' => 'Old Demo', 'brand_name' => '', 'category' => 'Demo', 'strength' => '1mg', 'dosage_form' => 'Tablet', 'prescription_required' => false]);
        $demoRow = BranchInventory::create(['branch_id' => 1, 'medicine_id' => $demo->id, 'price' => '1.00', 'stock_quantity' => 7, 'reserved_quantity' => 3, 'min_threshold' => 5, 'status' => 'available', 'version' => 4]);

        $this->seed([MedicineSeeder::class, BranchInventorySeeder::class]);

        $ceelin->refresh();
        $this->assertSame([2, 2, '151.19'], [$ceelin->stock_quantity, $ceelin->reserved_quantity, $ceelin->price], 'stock and price come from the file again, the reserved 2 is kept');
        $this->assertSame('low_stock', $ceelin->status);
        $this->assertSame(1, StockMovement::where('branch_inventory_id', $ceelin->id)->count(), 'a refresh is not a new row: no extra movement');

        $this->assertSame([7, 3, 4], [$demoRow->fresh()->stock_quantity, $demoRow->fresh()->reserved_quantity, $demoRow->fresh()->version]);
        $this->assertSame(114, BranchInventory::count());
    }

    public function test_reseeding_stops_with_a_clear_message_when_more_is_reserved_than_the_file_has(): void
    {
        $this->seedReal();
        $this->row(1, 'Ascorbic Acid + Zinc', 'Ceelin Plus', '100mg/10mg per 5mL, 120mL', 'Syrup')->update(['stock_quantity' => 50, 'reserved_quantity' => 40]);

        $message = $this->failure(BranchInventorySeeder::class);

        $this->assertStringContainsString('inventory_branch_1.csv line 2', $message);
        $this->assertStringContainsString('40 reserved', $message);
        $this->assertStringContainsString('quantity 2', $message);
    }

    // ------------------------------------------------------------ a medicine that is not in the catalog

    public function test_a_missing_medicine_names_the_file_line_and_medicine_and_nothing_is_written(): void
    {
        $this->useCsv($this->smallFiles("1,Amoxicillin,,500mg,Capsule,10,5.00,B1,2028-01-31,5\n1,Not A Real Medicine,Nobrand,5mg,Tablet,10,5.00,B2,2028-01-31,5\n"));
        $this->seed([BranchSeeder::class, MedicineSeeder::class]);

        $message = $this->failure(BranchInventorySeeder::class);

        $this->assertStringContainsString('inventory_branch_1.csv line 3', $message);
        $this->assertStringContainsString("'Not A Real Medicine' / brand 'Nobrand' / 5mg / Tablet", $message);
        $this->assertStringContainsString('medicine not found', $message);
        $this->assertSame(0, BranchInventory::count(), 'the valid first row was not written either');
        $this->assertSame(1, Medicine::count(), 'the inventory seeder never creates a medicine');
    }

    public function test_a_medicine_is_matched_on_the_whole_natural_key(): void
    {
        $this->useCsv($this->smallFiles("1,Amoxicillin,,500mg,Tablet,10,5.00,B1,2028-01-31,5\n")); // right name, wrong dosage form
        $this->seed([BranchSeeder::class, MedicineSeeder::class]);

        $this->assertStringContainsString('medicine not found', $this->failure(BranchInventorySeeder::class));
    }

    // ------------------------------------------------------------ files that cannot be read

    public function test_a_missing_csv_file_is_reported_by_name(): void
    {
        $this->useCsv(['inventory_branch_1.csv' => self::INVENTORY_HEADER."\n"]);

        $message = $this->failure(MedicineSeeder::class);

        $this->assertStringContainsString('Seed data file not found', $message);
        $this->assertStringContainsString('medicines_master.csv', $message);
    }

    public function test_an_empty_csv_file_is_reported(): void
    {
        $this->useCsv(['medicines_master.csv' => '']);

        $this->assertStringContainsString('no header row', $this->failure(MedicineSeeder::class));
    }

    public function test_a_row_with_the_wrong_number_of_columns_is_reported_with_its_line(): void
    {
        $this->useCsv(['medicines_master.csv' => self::MASTER_HEADER."\n".$this->amoxicillin()."\nIbuprofen,,Pain,Extra,200mg,Capsule,false,,\n"]);

        $message = $this->failure(MedicineSeeder::class);

        $this->assertStringContainsString('medicines_master.csv line 3', $message);
        $this->assertStringContainsString('expected 8 columns but found 9', $message);
    }

    public function test_a_quoted_comma_is_one_value_not_two(): void
    {
        $this->useCsv($this->smallFiles('', "Syrup Med,Brand,Respiratory,\"100mg per 5mL, 60mL\",Syrup,false,,\n"));

        $this->seed(MedicineSeeder::class);

        $this->assertSame('100mg per 5mL, 60mL', Medicine::where('generic_name', 'Syrup Med')->value('strength'));
    }

    public function test_a_bad_prescription_flag_is_reported_and_no_medicine_is_saved(): void
    {
        $this->useCsv(['medicines_master.csv' => self::MASTER_HEADER."\n".$this->amoxicillin()."\n".$this->amoxicillin('maybe')]);
        // the second row has the same key as the first on purpose: the first is valid, the second is not

        $message = $this->failure(MedicineSeeder::class);

        $this->assertStringContainsString('medicines_master.csv line 3', $message);
        $this->assertStringContainsString("prescription_required must be true or false, found 'maybe'", $message);
        $this->assertSame(0, Medicine::count(), 'all rows are checked before any is saved');
    }

    public function test_an_empty_required_value_and_an_over_long_value_are_reported(): void
    {
        $this->useCsv(['medicines_master.csv' => self::MASTER_HEADER."\n,,Antibiotics,500mg,Capsule,true,,\n"]);
        $this->assertStringContainsString("'generic_name' is empty", $this->failure(MedicineSeeder::class));

        $this->useCsv(['medicines_master.csv' => self::MASTER_HEADER."\nLong,,Antibiotics,".str_repeat('x', 51).",Capsule,true,,\n"]);
        $message = $this->failure(MedicineSeeder::class);
        $this->assertStringContainsString("'strength' is 51 characters but the column holds at most 50", $message);
    }

    public function test_bad_inventory_values_are_reported_with_their_file_and_line(): void
    {
        $cases = [
            'quantity must be a whole number' => "1,Amoxicillin,,500mg,Capsule,ten,5.00,B1,2028-01-31,5\n",
            'price must be a number' => "1,Amoxicillin,,500mg,Capsule,10,free,B1,2028-01-31,5\n",
            'min_threshold must be a whole number' => "1,Amoxicillin,,500mg,Capsule,10,5.00,B1,2028-01-31,-1\n",
            'expiration_date must be YYYY-MM-DD' => "1,Amoxicillin,,500mg,Capsule,10,5.00,B1,31/01/2028,5\n",
            'expiration_date must be YYYY-MM-DD ' => "1,Amoxicillin,,500mg,Capsule,10,5.00,B1,2028-02-31,5\n",
            'branch 9 not found' => "9,Amoxicillin,,500mg,Capsule,10,5.00,B1,2028-01-31,5\n",
            'branch_id must be a whole number' => "one,Amoxicillin,,500mg,Capsule,10,5.00,B1,2028-01-31,5\n",
            'batch_no is 51 characters' => '1,Amoxicillin,,500mg,Capsule,10,5.00,'.str_repeat('B', 51).",2028-01-31,5\n",
        ];

        foreach ($cases as $expected => $row) {
            $this->useCsv($this->smallFiles($row));
            $this->seed([BranchSeeder::class, MedicineSeeder::class]);

            $message = $this->failure(BranchInventorySeeder::class);

            $this->assertStringContainsString('inventory_branch_1.csv line 2', $message, $expected);
            $this->assertStringContainsString(trim($expected), $message);
            $this->assertSame(0, BranchInventory::count());
        }
    }

    public function test_the_same_medicine_listed_twice_for_one_branch_is_refused(): void
    {
        $this->useCsv($this->smallFiles("1,Amoxicillin,,500mg,Capsule,10,5.00,B1,2028-01-31,5\n1,Amoxicillin,,500mg,Capsule,20,6.00,B2,2028-01-31,5\n"));
        $this->seed([BranchSeeder::class, MedicineSeeder::class]);

        $message = $this->failure(BranchInventorySeeder::class);

        $this->assertStringContainsString('inventory_branch_1.csv line 3', $message);
        $this->assertStringContainsString('is listed twice for branch 1', $message);
        $this->assertStringContainsString('inventory_branch_1.csv line 2', $message);
    }
}
