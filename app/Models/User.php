<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ClientGroup;
use App\Domains\Shared\Models\StaffResponsibilityAssignment;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'client_group_id',
        'is_platform_admin',
        'is_employee',
        'employee_access_profile',
        'staff_responsibilities',
        'responsibilities_configured',
        'is_staff_supervisor',
        'supervisor_user_id',
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
            'staff_responsibilities' => 'array',
            'responsibilities_configured' => 'boolean',
            'is_staff_supervisor' => 'boolean',
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

    public function clientGroup(): BelongsTo
    {
        return $this->belongsTo(ClientGroup::class);
    }

    public function staffResponsibilityAssignments(): HasMany
    {
        return $this->hasMany(StaffResponsibilityAssignment::class);
    }

    public function activeStaffResponsibilityAssignments(): HasMany
    {
        return $this->staffResponsibilityAssignments()->activeNow();
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_user_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'supervisor_user_id');
    }

    public function isDirectSupervisorOf(self $user): bool
    {
        return (int) $user->supervisor_user_id === (int) $this->id;
    }

    public function directReportIds(): array
    {
        return $this->directReports()
            ->where('is_employee', true)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
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
        return ! $this->is_platform_admin && ! $this->is_employee && (filled($this->business_id) || filled($this->client_group_id));
    }

    public function defaultBusinessId(): ?int
    {
        if (filled($this->business_id)) {
            return (int) $this->business_id;
        }

        if (filled($this->client_group_id)) {
            return Business::query()
                ->where('client_group_id', $this->client_group_id)
                ->orderBy('name')
                ->value('id');
        }

        return null;
    }

    public function accessibleBusinessIds(): array
    {
        if ($this->seesAllBusinesses()) {
            return Business::query()->pluck('id')->all();
        }

        if ($this->isStaff() && (bool) $this->responsibilities_configured) {
            $assignedBusinessIds = $this->activeStaffResponsibilityAssignments()
                ->distinct()
                ->pluck('business_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all();

            if ($assignedBusinessIds !== []) {
                return $assignedBusinessIds;
            }

            if ($this->staffResponsibilities() !== [] && filled($this->business_id)) {
                return [(int) $this->business_id];
            }

            return [];
        }

        if (filled($this->client_group_id) && ! $this->isStaff()) {
            return Business::query()
                ->where('client_group_id', $this->client_group_id)
                ->orderBy('name')
                ->pluck('id')
                ->all();
        }

        return filled($this->business_id) ? [(int) $this->business_id] : [];
    }

    public function accessibleBusinessIdsForResponsibility(string|array $responsibilities): array
    {
        if (! $this->isStaff()) {
            return $this->accessibleBusinessIds();
        }

        if (! (bool) $this->responsibilities_configured) {
            return $this->hasStaffResponsibility($responsibilities) && filled($this->business_id)
                ? [(int) $this->business_id]
                : [];
        }

        $assignedBusinessIds = $this->activeStaffResponsibilityAssignments()
            ->whereIn('responsibility_code', (array) $responsibilities)
            ->distinct()
            ->pluck('business_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($assignedBusinessIds !== []) {
            return $assignedBusinessIds;
        }

        if ($this->hasStaffResponsibility($responsibilities) && filled($this->business_id)) {
            return [(int) $this->business_id];
        }

        if (in_array('supervisor_review', (array) $responsibilities, true) && (bool) $this->is_staff_supervisor && filled($this->business_id)) {
            return [(int) $this->business_id];
        }

        return [];
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

    public static function staffResponsibilityOptions(): array
    {
        return [
            'order_confirmation' => 'Order confirmation',
            'dispatch' => 'Dispatch',
            'return_recovery' => 'Returns and resends',
            'production' => 'Production and piece pay',
            'material_stock' => 'Material stock',
            'expense_recording' => 'Expenses and supplier dues',
            'collections' => 'Collections and service income',
            'bank_exceptions' => 'Bank exceptions',
            'product_repair' => 'Product/SKU repair',
            'supervisor_review' => 'Supervisor review',
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

    public function setStaffResponsibilitiesAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->attributes['staff_responsibilities'] = null;

            return;
        }

        $responsibilities = array_values(array_filter((array) $value, fn (mixed $responsibility): bool => filled($responsibility)));
        $valid = array_keys(static::staffResponsibilityOptions());
        $invalid = array_diff($responsibilities, $valid);

        if ($invalid !== []) {
            throw new \InvalidArgumentException('Invalid staff responsibility: '.implode(', ', $invalid));
        }

        $this->attributes['staff_responsibilities'] = json_encode($responsibilities);
        $this->attributes['responsibilities_configured'] = true;
    }

    public function staffResponsibilities(?int $businessId = null): array
    {
        if (! $this->isStaff()) {
            return [];
        }

        $assignmentQuery = $this->activeStaffResponsibilityAssignments();

        if (filled($businessId)) {
            $assignmentQuery->where('business_id', (int) $businessId);
        }

        $assignedResponsibilities = $assignmentQuery
            ->pluck('responsibility_code')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($assignedResponsibilities !== []) {
            return array_values(array_intersect($assignedResponsibilities, array_keys(static::staffResponsibilityOptions())));
        }

        $responsibilities = array_values(array_filter((array) ($this->staff_responsibilities ?? [])));
        $valid = array_keys(static::staffResponsibilityOptions());
        $responsibilities = array_values(array_intersect($responsibilities, $valid));

        if ((bool) $this->responsibilities_configured) {
            return $responsibilities;
        }

        return match ($this->employeeAccessProfileValue()) {
            'work_only' => ['order_confirmation', 'dispatch', 'return_recovery'],
            'operations' => ['order_confirmation', 'dispatch', 'return_recovery', 'production', 'material_stock', 'product_repair'],
            'finance_ops' => ['expense_recording', 'collections', 'bank_exceptions', 'product_repair'],
            'full_staff' => array_values(array_diff($valid, ['supervisor_review'])),
            default => ['order_confirmation', 'dispatch', 'return_recovery'],
        };
    }

    public function hasStaffResponsibility(string|array $responsibilities, ?int $businessId = null): bool
    {
        if (! $this->isStaff()) {
            return false;
        }

        return count(array_intersect((array) $responsibilities, $this->staffResponsibilities($businessId))) > 0;
    }

    public function canAccessOrderWork(?int $businessId = null): bool
    {
        return $this->hasStaffResponsibility(['order_confirmation', 'dispatch', 'return_recovery'], $businessId);
    }

    public function canAccessProductionWork(?int $businessId = null): bool
    {
        return $this->hasStaffResponsibility('production', $businessId);
    }

    public function canAccessMaterialWork(?int $businessId = null): bool
    {
        return $this->hasStaffResponsibility('material_stock', $businessId);
    }

    public function canAccessExpenseWork(?int $businessId = null): bool
    {
        return $this->hasStaffResponsibility('expense_recording', $businessId);
    }

    public function canAccessCollectionsWork(?int $businessId = null): bool
    {
        return $this->hasStaffResponsibility('collections', $businessId);
    }

    public function canAccessBankExceptionWork(?int $businessId = null): bool
    {
        return $this->hasStaffResponsibility('bank_exceptions', $businessId);
    }

    public function canAccessProductRepairWork(?int $businessId = null): bool
    {
        return $this->hasStaffResponsibility('product_repair', $businessId);
    }

    public function canAccessSupervisorReview(?int $businessId = null): bool
    {
        return $this->isStaff() && ((bool) $this->is_staff_supervisor || $this->hasStaffResponsibility('supervisor_review', $businessId));
    }
}
