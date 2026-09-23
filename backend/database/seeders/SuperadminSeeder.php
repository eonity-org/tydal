<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperadminSeeder extends Seeder
{
    /**
     * Run the database seeds to create the default platform superadmin.
     *
     * The password is resolved from TYDAL_SUPERADMIN_PASSWORD so no known
     * default ships in source. If it is blank, a strong random password is
     * generated and printed once below.
     */
    public function run(): void
    {
        $email = env('TYDAL_SUPERADMIN_EMAIL', 'superadmin@tydal.test');
        $password = (string) env('TYDAL_SUPERADMIN_PASSWORD', '');
        $passwordWasGenerated = $password === '';
        if ($passwordWasGenerated) {
            $password = Str::password(20);
        }

        $superAdmin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Platform Superadmin',
                'email' => $email,
                'password' => Hash::make($password),
                'is_superadmin' => true,
                'is_active' => true,
            ]
        );

        $this->command->info('=====================================');
        $this->command->info('Platform Superadmin Account:');
        $this->command->info('=====================================');
        $this->command->info("Email:    {$superAdmin->email}");
        if (! $superAdmin->wasRecentlyCreated) {
            $this->command->info('Password: (unchanged — user already existed)');
        } elseif ($passwordWasGenerated) {
            $this->command->info("Password: {$password}");
            $this->command->warn('⚠️  Randomly generated — it will NOT be shown again. Store it now.');
            $this->command->warn('⚠️  Set TYDAL_SUPERADMIN_PASSWORD to control it explicitly.');
        } else {
            $this->command->info('Password: (from TYDAL_SUPERADMIN_PASSWORD)');
            $this->command->info('⚠️  IMPORTANT: change this password in production!');
        }
        $this->command->info('=====================================');
    }
}
