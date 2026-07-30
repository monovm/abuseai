<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            'cases.view', 'cases.create', 'cases.edit', 'cases.delete', 'cases.action',
            'reports.view', 'reports.create',
            'customers.view', 'customers.edit',
            'ips.view', 'ips.create', 'ips.edit', 'ips.delete',
            'reporters.view', 'reporters.edit',
            'rules.view', 'rules.create', 'rules.edit', 'rules.delete',
            'templates.view', 'templates.create', 'templates.edit', 'templates.delete',
            'analytics.view',
            'ai.trigger',
            'users.view', 'users.create', 'users.edit',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Admin role — full access
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions($permissions);

        // Agent role — case management, no settings
        $agent = Role::firstOrCreate(['name' => 'agent']);
        $agent->syncPermissions([
            'cases.view', 'cases.edit', 'cases.action',
            'reports.view',
            'customers.view',
            'ips.view',
            'reporters.view',
            'analytics.view',
            'ai.trigger',
        ]);

        // Viewer role — read only
        $viewer = Role::firstOrCreate(['name' => 'viewer']);
        $viewer->syncPermissions([
            'cases.view',
            'reports.view',
            'customers.view',
            'ips.view',
            'analytics.view',
        ]);
    }
}
