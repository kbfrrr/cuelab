<?php

namespace Tests\Feature;

use App\Models\Tournament;
use App\Models\TournamentRegistration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TournamentConcurrencyTest extends TestCase
{
    public function test_simultaneous_registrations_cannot_take_the_same_final_seat(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'cuelab-db-');
        $worker = tempnam(sys_get_temp_dir(), 'cuelab-worker-');
        $previousDatabase = config('database.connections.sqlite.database');
        $processes = [];
        try {
            config(['database.connections.sqlite.database' => $database]);
            DB::purge('sqlite');
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            $table = Tournament::factory()->create(['status' => 'published', 'registration_deadline' => now()->addDays(2), 'entry_fee_minor' => 3000, 'capacity' => 1]);
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
    app(App\Services\TournamentService::class)->register(App\Models\Tournament::findOrFail((int)$argv[3]), [
        'full_name'=>'Concurrent player','phone'=>(string)random_int(9000000000,9999999999),
        'quoted_fee_minor'=>3000,'submission_key'=>(string)Illuminate\Support\Str::uuid(),
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
            $this->assertSame(1, TournamentRegistration::count());
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
