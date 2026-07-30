<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds the initial admin account(s) so a fresh install can log in.
 *
 * Each account gets a strong random password that is printed once to the
 * console — it is never stored anywhere else, so copy it immediately.
 * Set SEED_ADMIN_PASSWORD to pin a known password instead (useful for
 * automation and throwaway demo environments).
 *
 * Existing accounts are never touched: re-running the seeder will not
 * reset a password or lock anyone out.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['email' => 'admin@abuseai.io', 'name' => 'Admin'],
            ['email' => 'editor@abuseai.io', 'name' => 'Editor'],
        ];

        $fixedPassword = env('SEED_ADMIN_PASSWORD');
        $created = [];

        foreach ($accounts as $account) {
            $existing = User::where('email', $account['email'])->first();

            if ($existing) {
                $existing->assignRole('admin');

                continue;
            }

            $password = $fixedPassword ?: Str::password(20, letters: true, numbers: true, symbols: false, spaces: false);

            User::create([
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ])->assignRole('admin');

            $created[$account['email']] = $password;
        }

        $this->report($created, $fixedPassword !== null && $fixedPassword !== '');
    }

    /**
     * Print the freshly created credentials so the operator can log in.
     */
    private function report(array $created, bool $fixed): void
    {
        if ($this->command === null || $created === []) {
            return;
        }

        $this->command->newLine();
        $this->command->warn('========================================================');
        $this->command->warn('  Abuse AI — seeded admin account(s)');
        $this->command->warn('========================================================');

        foreach ($created as $email => $password) {
            $this->command->line("  email:    <fg=cyan>{$email}</>");
            $this->command->line("  password: <fg=cyan>{$password}</>");
            $this->command->newLine();
        }

        if ($fixed) {
            $this->command->warn('  Password set via SEED_ADMIN_PASSWORD. Change it before');
            $this->command->warn('  exposing this host to any untrusted network.');
        } else {
            $this->command->warn('  Randomly generated — it is NOT stored anywhere else.');
            $this->command->warn('  Copy it now, then change it after your first login.');
        }

        $this->command->warn('========================================================');
        $this->command->newLine();
    }
}
