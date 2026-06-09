<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use App\Domains\Shared\Models\Business;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'business_id',
        'is_platform_admin',
        'is_employee',
        'employee_access_profile',
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
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'is_employee' => 'boolean',
            'employee_access_profile' => 'string',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function seesAllBusinesses(): bool
    {
        return $this->is_platform_admin;
    }

    public function isInternalAdmin(): bool
    {
        return $this->is_platform_admin;
    }

    public function isOwner(): bool
    {
        return $this->is_platform_admin || (! $this->is_employee && filled($this->business_id));
    }

    public function isStaff(): bool
    {
        return (bool) $this->is_employee;
    }

    public function isManager(): bool
    {
        return $this->isOwner();
    }

    public function isEmployee(): bool
    {
        return $this->isStaff();
    }

    public static function employeeAccessProfileOptions(): array
    {
        return [
            'work_only' => 'Work only',
            'operations' => 'Operations',
            'finance_ops' => 'Finance operations',
            'full_staff' => 'Full staff access',
        ];
    }

    public function employeeAccessProfileValue(): string
    {
        $profile = trim((string) ($this->employee_access_profile ?? 'operations'));

        return array_key_exists($profile, static::employeeAccessProfileOptions()) ? $profile : 'operations';
    }

    public function canAccessWorkOnlyTasks(): bool
    {
        return $this->isStaff() && in_array($this->employeeAccessProfileValue(), ['work_only', 'operations', 'finance_ops', 'full_staff'], true);
    }

    public function canAccessOperationalTasks(): bool
    {
        return $this->isStaff() && in_array($this->employeeAccessProfileValue(), ['operations', 'finance_ops', 'full_staff'], true);
    }

    public function canAccessFinanceOperations(): bool
    {
        return $this->isStaff() && in_array($this->employeeAccessProfileValue(), ['finance_ops', 'full_staff'], true);
    }

    public function canAccessFullStaff(): bool
    {
        return $this->isStaff() && $this->employeeAccessProfileValue() === 'full_staff';
    }
}
