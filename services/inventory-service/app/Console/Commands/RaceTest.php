<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Medicine;
use App\Models\StockHold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * T20: N independent OS processes ask for 1 unit of the same stock row at the same moment.
 * Needs a real MySQL database (row locks): in-memory SQLite cannot be shared between processes.
 */
class RaceTest extends Command
{
    protected $signature = 'inventory:race-test
        {--workers=20 : number of competing OS processes}
        {--stock=5 : stock of the temporary row (reserved starts at 0)}
        {--delay=10 : seconds the workers get to boot before the start barrier}';

    protected $description = 'Race test (T20): concurrent holds on one stock row must never oversell. Creates and deletes its own temporary rows.';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('inventory:race-test refuses to run when APP_ENV=production.');

            return self::FAILURE;
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('inventory:race-test needs a real MySQL database shared between processes (current driver: '.DB::getDriverName().').');

            return self::FAILURE;
        }

        $workers = max(2, (int) $this->option('workers'));
        $stock = max(1, (int) $this->option('stock'));
        $delay = max(3, (int) $this->option('delay'));

        $branch = Branch::where('is_active', true)->orderBy('id')->first();
        if (! $branch) {
            $this->error('No active branch found. Run php artisan db:seed first.');

            return self::FAILURE;
        }

        $run = Str::lower(Str::random(8));
        $prefix = "RACE-{$run}-";
        $medicine = null;
        $inventory = null;
        $report = null;
        $untouched = null;

        // Fingerprint of every pre-existing stock row: the test must not change any of them.
        $before = $this->fingerprint();

        try {
            $medicine = Medicine::create([
                'generic_name' => "RACE-TEST-{$run}",
                'brand_name' => '',
                'category' => 'Race test',
                'strength' => '1mg',
                'dosage_form' => 'Tablet',
                'prescription_required' => false,
            ]);
            $inventory = BranchInventory::create([
                'branch_id' => $branch->id,
                'medicine_id' => $medicine->id,
                'price' => '1.00',
                'stock_quantity' => $stock,
                'reserved_quantity' => 0,
                'min_threshold' => 1,
                'status' => BranchInventory::statusFor($stock, 1),
                'version' => 1,
            ]);

            $this->line("Temporary row: branch {$branch->id} ({$branch->name}), medicine #{$medicine->id}, inventory #{$inventory->id}, stock {$stock}, reserved 0");
            $this->line("Starting {$workers} OS processes; start barrier in {$delay}s ...");

            $startAt = microtime(true) + $delay;
            $processes = [];
            for ($i = 1; $i <= $workers; $i++) {
                $process = new Process([
                    PHP_BINARY, base_path('artisan'), 'inventory:race-worker',
                    $prefix.$i, (string) $branch->id, (string) $medicine->id, sprintf('%.6F', $startAt),
                ], base_path());
                $process->setTimeout($delay + 90);
                $process->start();
                $processes[$i] = $process;
            }

            $results = [];
            foreach ($processes as $i => $process) {
                $process->wait();
                $results[$i] = $this->parseWorker($process);
            }

            $report = $this->analyse($results, $inventory->id, $prefix, $workers, $stock);
        } finally {
            $cleanup = $this->cleanup($prefix, $inventory, $medicine);
        }

        $untouched = $before === $this->fingerprint();

        if ($report === null) {
            $this->error('The race test could not be completed.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['workers', $workers],
            ['stock', $stock],
            ['holds succeeded', $report['success']],
            ['STOCK_INSUFFICIENT', $report['insufficient']],
            ['other errors', $report['errors']],
            ['workers late for the barrier', $report['late']],
            ['final reserved_quantity', $report['reserved']],
            ['final stock_quantity', $report['stock']],
            ['hold rows created', $report['holds']],
            ['slowest worker call (ms)', $report['max_ms']],
        ]);

        $checks = $report['checks'] + [
            'temporary rows deleted' => $cleanup,
            'seeded stock rows untouched' => $untouched,
        ];
        foreach ($checks as $name => $ok) {
            $this->line(($ok ? '  PASS  ' : '  FAIL  ').$name);
        }

        if ($report['late'] > 0) {
            $this->warn("{$report['late']} worker(s) booted after the barrier, so they were not perfectly simultaneous. Re-run with a larger --delay for a stricter test.");
        }

        $passed = ! in_array(false, $checks, true);
        $this->newLine();
        $passed ? $this->info('RESULT: PASSED, no overselling.') : $this->error('RESULT: FAILED.');

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function parseWorker(Process $process): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $process->getOutput())));
        $json = json_decode((string) end($lines), true);

        return is_array($json)
            ? $json
            : ['outcome' => 'error', 'code' => 'NO_OUTPUT', 'message' => mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()), 0, 160), 'late' => false, 'ms' => 0];
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<string, mixed>
     */
    private function analyse(array $results, int $inventoryId, string $prefix, int $workers, int $stock): array
    {
        $count = fn (string $outcome) => count(array_filter($results, fn ($r) => $r['outcome'] === $outcome));
        $success = $count('success');
        $insufficient = $count('insufficient');
        $errors = $count('error');

        foreach ($results as $r) {
            if ($r['outcome'] === 'error') {
                $this->warn("  worker {$r['ref']}: ".($r['code'] ?? '?').' '.($r['message'] ?? ''));
            }
        }

        $row = BranchInventory::findOrFail($inventoryId);
        $holds = StockHold::where('reservation_ref', 'like', $prefix.'%')->get();
        $expectedWins = min($workers, $stock);

        return [
            'success' => $success,
            'insufficient' => $insufficient,
            'errors' => $errors,
            'late' => count(array_filter($results, fn ($r) => $r['late'] ?? false)),
            'reserved' => $row->reserved_quantity,
            'stock' => $row->stock_quantity,
            'holds' => $holds->count(),
            'max_ms' => (int) max(array_column($results, 'ms') ?: [0]),
            'checks' => [
                "exactly {$expectedWins} succeeded" => $success === $expectedWins,
                'every other worker got STOCK_INSUFFICIENT' => $insufficient === $workers - $expectedWins && $errors === 0,
                "final reserved_quantity is {$expectedWins}" => $row->reserved_quantity === $expectedWins,
                'reserved is never negative or above stock' => $row->reserved_quantity >= 0 && $row->reserved_quantity <= $row->stock_quantity,
                'stock_quantity unchanged' => $row->stock_quantity === $stock,
                'hold rows match successes' => $holds->count() === $success && $holds->where('status', 'held')->count() === $success,
                'sum of held quantities equals reserved' => $holds->sum('quantity') === $row->reserved_quantity,
                'no reservation_ref holds the row twice' => $holds->pluck('reservation_ref')->unique()->count() === $holds->count(),
            ],
        ];
    }

    private function cleanup(string $prefix, ?BranchInventory $inventory, ?Medicine $medicine): bool
    {
        try {
            DB::transaction(function () use ($prefix, $inventory, $medicine) {
                StockHold::where('reservation_ref', 'like', $prefix.'%')->delete();
                if ($inventory) {
                    $inventory->movements()->delete();
                    $inventory->alerts()->delete();
                    $inventory->delete();
                }
                $medicine?->delete();
            });
        } catch (Throwable $e) {
            $this->error('Cleanup failed: '.$e->getMessage());

            return false;
        }

        return StockHold::where('reservation_ref', 'like', $prefix.'%')->doesntExist()
            && ($medicine === null || Medicine::whereKey($medicine->id)->doesntExist())
            && ($inventory === null || BranchInventory::whereKey($inventory->id)->doesntExist());
    }

    private function fingerprint(): string
    {
        return md5(json_encode([
            BranchInventory::orderBy('id')->get(['id', 'stock_quantity', 'reserved_quantity', 'status', 'version'])->toArray(),
            StockHold::where('reservation_ref', 'not like', 'RACE-%')->count(),
        ]));
    }
}
