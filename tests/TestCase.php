<?php

namespace Tests;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the spatie/permission roles before every test. RefreshDatabase
     * (applied via tests/Pest.php) rolls back at the end of each test, so
     * roles need re-creating each run. Without this, $user->assignRole('admin')
     * throws RoleDoesNotExist and route-level role:admin gates always 403.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }
}
