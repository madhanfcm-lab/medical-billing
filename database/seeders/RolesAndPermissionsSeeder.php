<?php

namespace Database\Seeders;

use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /** Every permission in the system; later phases only *use* these. */
    public const PERMISSIONS = [
        'dashboard.view',
        'masters.manage',
        'patients.view', 'patients.manage',
        'visits.view', 'visits.manage',
        'invoices.view', 'invoices.create', 'invoices.cancel',
        'payments.collect',
        'emi.view', 'emi.manage', 'emi.reschedule', 'emi.cancel',
        'followups.view', 'followups.manage',
        'appointments.view', 'appointments.manage',
        'attendance.view', 'attendance.manage', 'attendance.edit_past',
        'employees.manage', 'salary.manage',
        'reports.view',
        'audit.view',
        'settings.manage', 'users.manage', 'branches.manage',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        RoleModel::findOrCreate(Role::Admin->value, 'web')->syncPermissions(self::PERMISSIONS);

        RoleModel::findOrCreate(Role::Receptionist->value, 'web')->syncPermissions([
            'dashboard.view', 'patients.view', 'patients.manage', 'visits.view', 'visits.manage',
            'invoices.view', 'invoices.create', 'payments.collect',
            'emi.view', 'emi.manage', 'followups.view', 'followups.manage',
            'appointments.view', 'appointments.manage',
            'attendance.view', 'attendance.manage',
        ]);

        // Doctors: notes, diagnosis, follow-ups. No payment or invoice edit rights.
        RoleModel::findOrCreate(Role::Doctor->value, 'web')->syncPermissions([
            'dashboard.view', 'patients.view', 'visits.view', 'visits.manage',
            'invoices.view', 'emi.view', 'followups.view', 'followups.manage',
            'appointments.view',
        ]);
    }
}
