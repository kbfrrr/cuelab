<?php

namespace App\Console\Commands;

use Database\Seeders\CueLabDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class RunCueLabDemo extends Command
{
    protected $signature = 'cuelab:demo
                            {--serve : Start the demo site after seeding}
                            {--port=8001 : Local port for the demo site}';

    protected $description = 'Create or refresh CueLab’s separate SQLite demo database';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Run the demo command in the local environment.');

            return self::FAILURE;
        }

        $serve = $this->option('serve');
        $port = filter_var($this->option('port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($serve && $port === false) {
            $this->error('The demo port must be between 1 and 65535.');

            return self::FAILURE;
        }
        if ($serve && app()->configurationIsCached()) {
            $this->error('Run php artisan config:clear before starting the demo server so it can read the demo database settings.');

            return self::FAILURE;
        }

        $database = database_path(CueLabDemoSeeder::DATABASE_FILE);
        if (! is_file($database) && ! touch($database)) {
            $this->error('Could not create the demo database.');

            return self::FAILURE;
        }

        $originalConnection = DB::getDefaultConnection();
        config(['database.connections.cuelab_demo' => [
            ...config('database.connections.sqlite'),
            'database' => $database,
            'url' => null,
        ]]);
        DB::setDefaultConnection('cuelab_demo');

        try {
            $migrationResult = $this->call('migrate', ['--database' => 'cuelab_demo', '--no-interaction' => true]);
            if ($migrationResult !== self::SUCCESS) {
                return $migrationResult;
            }

            $seedResult = $this->call('db:seed', ['--database' => 'cuelab_demo', '--class' => CueLabDemoSeeder::class, '--no-interaction' => true]);
            if ($seedResult !== self::SUCCESS) {
                return $seedResult;
            }
        } finally {
            DB::purge('cuelab_demo');
            DB::setDefaultConnection($originalConnection);
        }

        if (! $serve) {
            $this->info('Demo database is ready. Use php artisan cuelab:demo --serve to open it in the browser.');

            return self::SUCCESS;
        }

        $server = new Process([
            PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', "--port={$port}", '--tries=1', '--no-reload', '--no-interaction',
        ], base_path(), [
            'APP_ENV' => 'local',
            'APP_URL' => "http://127.0.0.1:{$port}",
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_URL' => 'sqlite://localhost?database='.rawurlencode($database),
            'SESSION_DRIVER' => 'database',
            'SESSION_CONNECTION' => 'sqlite',
            'SESSION_COOKIE' => 'cuelab_demo_session',
            'CACHE_STORE' => 'database',
            'DB_CACHE_CONNECTION' => 'sqlite',
            'DB_CACHE_LOCK_CONNECTION' => 'sqlite',
        ]);
        $server->setTimeout(null);

        return $server->run(fn (string $type, string $output) => $this->output->write($output));
    }
}
