<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /** This login's staff record (attendance, salary), if they are on the staff list. */
    public function employee(): HasOne
    {
        // Whichever branch the staff record is in: a login is one person, however many branches are open.
        return $this->hasOne(Employee::class)->withoutGlobalScope('branch');
    }

    /** Logins that are not on the staff list in any branch yet. */
    public function scopeWithoutStaffRecord(Builder $q): Builder
    {
        return $q->whereNotIn('users.id', Employee::withoutGlobalScopes()->whereNotNull('user_id')->select('user_id'));
    }

    public function doctorProfile(): HasOne
    {
        return $this->hasOne(Doctor::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->orderBy('branches.name');
    }

    /** Active branches this person may work in: admins see every branch, everyone else only the ones assigned to them. */
    public function accessibleBranches(): Collection
    {
        $query = $this->hasRole(Role::Admin->value) ? Branch::query() : $this->branches();

        return $query->where('branches.is_active', true)->orderBy('branches.name')->get();
    }

    public function getRoleNameAttribute(): ?string
    {
        return $this->roles->first()?->name;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
