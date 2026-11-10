<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateStaff extends Command
{
    protected $signature = 'cuelab:create-staff {email} {--name=}';

    protected $description = 'Create a staff account with a password entered privately';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run interactively to enter a private password.');

            return self::FAILURE;
        }
        $data = ['email' => $this->argument('email'), 'name' => $this->option('name') ?: $this->ask('Staff name'), 'password' => $this->secret('Password (at least 12 characters)'), 'password_confirmation' => $this->secret('Confirm password')];
        $validator = Validator::make($data, ['email' => 'required|email|unique:users,email', 'name' => 'required|string|max:150', 'password' => 'required|string|min:12|confirmed']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

return self::FAILURE;
        }
        $user = new User;
        $user->forceFill(['email' => $data['email'], 'name' => $data['name'], 'password' => $data['password'], 'is_staff' => true])->save();
        $this->info('Staff account created. Sign in at /admin.');

        return self::SUCCESS;
    }
}
