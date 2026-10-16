<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;

class EmployeeService
{
    /**
     * Put a login-holder on the staff list (attendance and salary). Does nothing if they are already there.
     *
     * @param  array{designation?: ?string, joining_date?: ?string}  $data
     */
    public function createForUser(User $user, array $data = []): Employee
    {
        if ($existing = Employee::withoutGlobalScope('branch')->withTrashed()->where('user_id', $user->id)->first()) {
            return $existing;
        }

        $joined = filled($data['joining_date'] ?? null) ? Carbon::parse($data['joining_date']) : today();

        return Employee::create([
            'user_id' => $user->id,
            'branch_id' => $user->branches()->orderBy('branches.id')->value('branches.id'), // else the branch being worked in
            'employee_code' => Employee::suggestCode(),
            'name' => $user->name,
            'designation' => filled($data['designation'] ?? null) ? $data['designation'] : Role::tryFrom((string) $user->role_name)?->label(),
            'phone' => $user->phone,
            'joining_date' => $joined->gt(today()) ? today() : $joined,
            'is_active' => $user->is_active,
        ]);
    }

    /** Keep the staff record's name and phone in step with the login. */
    public function syncFromUser(User $user): void
    {
        Employee::withoutGlobalScope('branch')->where('user_id', $user->id)->update(['name' => $user->name, 'phone' => $user->phone]);
    }

    /**
     * One click for everyone who can log in but is not on the staff list yet.
     *
     * @return int how many were added
     */
    public function addAllLogins(): int
    {
        $added = 0;
        foreach (User::query()->withoutStaffRecord()->orderBy('id')->get() as $user) {
            $this->createForUser($user, ['joining_date' => $user->created_at?->toDateString()]);
            $added++;
        }

        return $added;
    }
}
