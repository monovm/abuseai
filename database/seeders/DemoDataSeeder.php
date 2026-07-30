<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Sample reporters, customers and abuse cases for local development and demos.
 *
 * These seeders use model factories and fake(), which come from
 * fakerphp/faker — a dev dependency. That means this seeder CANNOT run in the
 * production container image (built with `composer install --no-dev`); run it
 * from a development checkout:
 *
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Run DatabaseSeeder first — the cases created here attach to the IP addresses
 * it seeds.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! function_exists('fake')) {
            throw new RuntimeException(
                'DemoDataSeeder needs fakerphp/faker, which is a dev dependency and is '
                .'absent from the production image. Run it from a development checkout '
                .'(composer install without --no-dev).'
            );
        }

        // Order matters: abuse cases reference reporters and customers.
        $this->call([
            ReporterSeeder::class,
            CustomerSeeder::class,
            AbuseCaseSeeder::class,
        ]);
    }
}
