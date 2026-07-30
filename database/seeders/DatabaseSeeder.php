<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds only what a real deployment needs: roles, the admin account, and the
 * reference data the app cannot function without.
 *
 * Every seeder listed here must work in the production container, which is
 * built with `composer install --no-dev` and therefore has no fakerphp/faker.
 * Anything that needs factories or fake() belongs in DemoDataSeeder instead —
 * adding it here breaks AUTO_SEED=true, and because the entrypoint runs under
 * `set -e` that takes the whole container down.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminSeeder::class,
            IpAddressSeeder::class,
            AutomationRuleSeeder::class,
            EmailTemplateSeeder::class,
        ]);
    }
}
