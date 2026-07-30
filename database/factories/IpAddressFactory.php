<?php

namespace Database\Factories;

use App\Models\IpAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

class IpAddressFactory extends Factory
{
    protected $model = IpAddress::class;

    public function definition(): array
    {
        return [
            'ip_address' => fake()->unique()->ipv4(),
            // /32 for v4 (single host) — matches the migration's backfill
            // for pre-existing rows. The column is NOT NULL after the
            // 2026_04_21 migration, so factories must set it explicitly.
            'prefix_length' => 32,
            'server_name' => 'srv-' . fake()->numberBetween(1, 999),
            'status' => 'active',
        ];
    }
}
