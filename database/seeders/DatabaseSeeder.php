<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\BillingService;
use App\Support\Money;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the admin account (credentials via ADMIN_EMAIL / ADMIN_PASSWORD
     * env vars — used once at deploy time; nothing sensitive is committed).
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL/ADMIN_PASSWORD not set — skipping admin seed.');

            return;
        }

        $admin = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Administrator'),
                'password' => $password,
                'is_admin' => true,
                'email_verified_at' => now(),
            ],
        );

        // Start the admin with a working balance for smoke tests.
        app(BillingService::class)->purchase($admin, Money::fromDollars(10), 'Initial admin balance (seed)');

        $this->command?->info("Admin user ready: {$email}");
    }
}
