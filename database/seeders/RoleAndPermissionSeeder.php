<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $guards = ['web', 'sanctum'];

        // 1. Define Granular Permissions
        $permissionNames = [
            'orders.create',
            'orders.view',
            'inventory.view',
            'inventory.manage',
            'payments.cash',
            'payments.view',
            'invoices.amend_request',
            'invoices.amend_approve',
            'pickup.validate',
            'pickup.release',
            'expenses.create',
            'expenses.view',
            'bank_deposits.create',
            'bank_deposits.view',
            'reconciliation.eod',
            'reconciliation.view',
            'audit.view',
        ];

        foreach ($guards as $guard) {
            foreach ($permissionNames as $permissionName) {
                Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => $guard]);
            }

            // Super Admin
            $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => $guard]);
            $superAdmin->syncPermissions(Permission::where('guard_name', $guard)->get());

            // Admin
            $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => $guard]);
            $admin->syncPermissions(Permission::where('guard_name', $guard)->get());

            // Manager
            $manager = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => $guard]);
            $manager->syncPermissions([
                'orders.create',
                'orders.view',
                'inventory.view',
                'payments.view',
                'invoices.amend_request',
                'invoices.amend_approve',
                'pickup.validate',
                'pickup.release',
                'expenses.create',
                'expenses.view',
                'bank_deposits.view',
                'reconciliation.eod',
                'reconciliation.view',
            ]);

            // Cashier
            $cashier = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => $guard]);
            $cashier->syncPermissions([
                'orders.view',
                'inventory.view',
                'payments.cash',
                'payments.view',
                'expenses.view',
                'bank_deposits.create',
                'bank_deposits.view',
            ]);

            // Staff
            $staff = Role::firstOrCreate(['name' => 'Staff', 'guard_name' => $guard]);
            $staff->syncPermissions([
                'orders.create',
                'orders.view',
                'inventory.view',
                'invoices.amend_request',
                'pickup.validate',
                'pickup.release',
                'expenses.create',
            ]);

            // Salesperson
            $salesperson = Role::firstOrCreate(['name' => 'Salesperson', 'guard_name' => $guard]);
            $salesperson->syncPermissions([
                'orders.create',
                'orders.view',
                'inventory.view',
                'invoices.amend_request',
                'pickup.validate',
            ]);
        }
    }
}
