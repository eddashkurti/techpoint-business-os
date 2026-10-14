<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\BusinessSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateOwner extends Command
{
    protected $signature = 'techpoint:create-owner {email} {--name=Pronari TechPoint} {--password-file= : An ignored local file; never pass the password as an argument}';

    protected $description = 'Create the initial owner securely; existing users are never overwritten';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::where('email', $email)->exists()) {
            $this->error('Email invalid or account already exists.');

            return self::FAILURE;
        }
        $path = $this->option('password-file');
        $password = $path ? trim(file_get_contents($path)) : $this->secret('Password (minimum 12 characters)');
        if (! is_string($password) || strlen($password) < 12) {
            $this->error('Use at least 12 characters.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($email, $password) {
            (new BusinessSeeder)->run();
            $user = User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->roles()->attach(Role::where('name', 'owner')->firstOrFail());
            DB::table('audit_events')->insert(['user_id' => $user->id, 'action' => 'owner.created', 'subject_type' => 'user', 'subject_id' => (string) $user->id, 'details' => '{}', 'created_at' => now()]);
        });
        $this->info('Owner created. Password was not logged.');

        return self::SUCCESS;
    }
}
