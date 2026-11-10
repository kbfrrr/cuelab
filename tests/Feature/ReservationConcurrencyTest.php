<?php

namespace Tests\Feature;

use App\Models\BilliardTable;
use App\Models\BusinessSetting;
use App\Models\OperatingHour;
use App\Models\Reservation;
use App\Models\ServiceRate;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReservationConcurrencyTest extends TestCase
{
    public function test_simultaneous_requests_cannot_reserve_the_same_table(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'cuelab-db-');
        $worker = tempnam(sys_get_temp_dir(), 'cuelab-worker-');
        $previousDatabase = config('database.connections.sqlite.database');
        $processes = [];
        try {
            config(['database.connections.sqlite.database' => $database]);
            DB::purge('sqlite');
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            BusinessSetting::create(['key' => 'booking_enabled', 'value' => true, 'is_verified' => true]);
            foreach (range(0, 6) as $day) {
                OperatingHour::create(['day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '23:00', 'is_verified' => true]);
            }
            $rate = ServiceRate::factory()->create(['amount_minor' => 3000, 'is_verified' => true]);
            $table = BilliardTable::factory()->for($rate)->create();
            file_put_contents($worker, <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[2], 'database.connections.sqlite.url' => null]);
Illuminate\Support\Facades\DB::purge('sqlite');
echo "ready\n";
flush();
try {
    app(App\Services\ReservationService::class)->create([
        'billiard_table_id' => (int) $argv[3],
        'starts_at' => now()->addDay()->setTime(12, 0)->toDateTimeString(),
        'minutes' => 60, 'customer_name' => 'Concurrency test', 'phone' => '09171234567',
        'hourly_rate' => 3000, 'submission_key' => (string) Illuminate\Support\Str::uuid(),
    ]);
    echo 'booked';
} catch (Illuminate\Validation\ValidationException $exception) {
    echo 'conflict';
}
PHP);
            DB::beginTransaction();
            foreach (range(1, 2) as $index) {
                $process = new Process([PHP_BINARY, $worker, base_path(), $database, (string) $table->id], base_path(), ['APP_ENV' => 'testing']);
                $process->setTimeout(15)->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'ready')), $process->getErrorOutput());
            }
            DB::commit();
            $outcomes = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $outcomes[] = trim(str_replace('ready', '', $process->getOutput()));
            }
            sort($outcomes);
            $this->assertSame(['booked', 'conflict'], $outcomes);
            $this->assertSame(1, Reservation::count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('sqlite');
            config(['database.connections.sqlite.database' => $previousDatabase]);
            unlink($worker);
            unlink($database);
        }
    }
}
