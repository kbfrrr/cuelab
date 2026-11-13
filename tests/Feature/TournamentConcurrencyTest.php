<?php

namespace Tests\Feature;

use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;
use App\Services\TournamentPaymentProofService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            $table = Tournament::factory()->create(['status' => 'published', 'registration_deadline' => now()->addDays(2), 'entry_fee_minor' => 0, 'capacity' => 1]);
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
        'quoted_fee_minor'=>0,'submission_key'=>(string)Illuminate\Support\Str::uuid(),
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

    public function test_simultaneous_reviews_cannot_approve_the_same_gcash_reference(): void
    {
        $driver = DB::getDriverName();
        $previousDatabase = config('database.connections.sqlite.database');
        $database = $driver === 'sqlite' ? tempnam(sys_get_temp_dir(), 'cuelab-proof-db-') : null;
        $worker = tempnam(sys_get_temp_dir(), 'cuelab-proof-worker-');
        $processes = [];

        try {
            if ($database !== null) {
                config(['database.connections.sqlite.database' => $database]);
                DB::purge('sqlite');
            }
            $this->artisan('migrate')->assertSuccessful();

            $staff = User::factory()->create(['is_staff' => true]);
            $proofs = app(TournamentPaymentProofService::class);
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jE1sAAAAASUVORK5CYII=');
            $reference = 'CONCURRENT'.Str::upper(Str::random(20));
            $reviews = [];
            foreach (range(1, 2) as $index) {
                $event = Tournament::factory()->create(['status' => 'published']);
                $record = TournamentRegistration::factory()->for($event)->create(['status' => 'unpaid', 'quoted_fee_minor' => 3000]);
                $proofs->submit($record, $reference, UploadedFile::fake()->createWithContent('receipt.png', $png));
                $reviews[$record->id] = $proofs->currentProof($record)->version;
            }

            file_put_contents($worker, <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($argv[2] !== '') {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[2], 'database.connections.sqlite.url' => null]);
}
$registration = App\Models\TournamentRegistration::findOrFail((int) $argv[3]);
$staff = App\Models\User::findOrFail((int) $argv[4]);
echo "ready\n";
flush();
try {
    app(App\Services\TournamentPaymentProofService::class)->review($registration, $staff, $argv[5]);
    echo 'approved';
} catch (Illuminate\Validation\ValidationException $exception) {
    echo str_contains($exception->getMessage(), 'already been used') ? 'duplicate' : $exception->getMessage();
}
PHP);

            DB::beginTransaction();
            if ($driver === 'pgsql') {
                TournamentRegistration::whereIn('id', array_keys($reviews))->lockForUpdate()->get();
            }
            foreach ($reviews as $registrationId => $version) {
                $process = new Process(
                    [PHP_BINARY, $worker, base_path(), $database ?? '', (string) $registrationId, (string) $staff->id, $version],
                    base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => $driver, 'DB_URL' => config("database.connections.{$driver}.url") ?: false],
                );
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
            $this->assertSame(['approved', 'duplicate'], $outcomes);
            $this->assertSame(1, TournamentRegistration::whereIn('id', array_keys($reviews))->where('status', 'paid')->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($database !== null) {
                DB::purge('sqlite');
                config(['database.connections.sqlite.database' => $previousDatabase]);
                unlink($database);
            } elseif (isset($reviews)) {
                app(TournamentPaymentProofService::class)->proofs()->whereIn('tournament_registration_id', array_keys($reviews))->delete();
                TournamentRegistration::whereIn('id', array_keys($reviews))->delete();
            }
            unlink($worker);
        }
    }
}
