<?php

namespace App\Models;

use App\Enums\BloodGroup;
use App\Enums\Gender;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\TracksUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes, TracksUser;

    protected $fillable = [
        'name', 'gender', 'date_of_birth', 'phone', 'alternate_phone', 'email', 'address',
        'blood_group', 'allergies', 'emergency_contact_name', 'emergency_contact_phone',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'gender' => Gender::class,
            'blood_group' => BloodGroup::class,
        ];
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function emiPlans(): HasMany
    {
        return $this->hasMany(EmiPlan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Doctors only see patients they have treated (a visit under their own Doctor profile).
     * Everyone else sees all patients.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if (! $user->hasRole('doctor')) {
            return $q;
        }

        return $q->whereIn('patients.id', Visit::query()->where('doctor_id', $user->doctorProfile?->id ?? 0)->select('patient_id'));
    }

    /** "34 yrs", "8 mths" or "12 days"; "—" when DOB is unknown. */
    public function getAgeLabelAttribute(): string
    {
        if (! $this->date_of_birth) {
            return '—';
        }
        $dob = $this->date_of_birth;
        $years = $dob->diffInYears(now(), true);
        if ($years >= 1) {
            return (int) $years.' yrs';
        }
        $months = (int) $dob->diffInMonths(now(), true);

        return $months >= 1 ? $months.' mths' : (int) $dob->diffInDays(now(), true).' days';
    }

    /** Name, phone or UHID contains $term. */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $q->where(fn ($w) => $w->where('name', 'like', $like)
            ->orWhere('phone', 'like', $like)
            ->orWhere('uhid', 'like', $like));
    }
}
