<?php

namespace App\Filament\Pages;

use App\Domains\Shared\Models\StaffResponsibilityAssignment;
use App\Domains\Shared\Models\StaffResponsibilityAudit;
use App\Filament\Resources\StaffResponsibilityAssignmentResource;
use App\Filament\Resources\StaffResponsibilityAuditResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class LegacyAccessMigrationStatus extends Page
{
    protected static ?string $slug = 'legacy-access-migration-status';
    protected static ?string $navigationGroup = 'Team';
    protected static ?string $navigationLabel = 'Legacy Migration Status';
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?int $navigationSort = 80;
    protected static string $view = 'filament.pages.legacy-access-migration-status';

    public array $rows = [];

    public function mount(): void
    {
        $this->refreshRows();
    }

    protected function getViewData(): array
    {
        return [
            'rows' => $this->rows,
            'summary' => $this->summary(),
            'responsibilityManagerUrl' => StaffResponsibilityAssignmentResource::getUrl('index'),
            'auditUrl' => StaffResponsibilityAuditResource::getUrl('index'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check() && (($user?->isOwner() ?? false) || ($user?->isInternalAdmin() ?? false));
    }

    public function assignNoAccess(int $userId): void
    {
        $staff = $this->staffQuery()->whereKey($userId)->firstOrFail();
        $before = [
            'responsibilities_configured' => (bool) $staff->responsibilities_configured,
            'staff_responsibilities' => $staff->staff_responsibilities,
            'legacy_profile' => $staff->employee_access_profile,
        ];

        StaffResponsibilityAssignment::query()
            ->where('user_id', $staff->id)
            ->where('is_active', true)
            ->get()
            ->each(fn (StaffResponsibilityAssignment $assignment) => $assignment->deactivate(Auth::user(), 'No-access migration decision'));

        $staff->forceFill([
            'staff_responsibilities' => [],
            'responsibilities_configured' => true,
        ])->save();

        StaffResponsibilityAudit::query()->create([
            'user_id' => $staff->id,
            'business_id' => $staff->business_id,
            'event_type' => 'legacy_no_access',
            'previous_access' => $before,
            'new_access' => [
                'responsibilities_configured' => true,
                'staff_responsibilities' => [],
                'fallback_retired' => true,
            ],
            'changed_by' => Auth::id(),
            'reason' => 'Owner assigned explicit no-access from legacy migration status.',
        ]);

        $this->refreshRows();

        Notification::make()->title('Explicit no-access assigned')->success()->send();
    }

    public function retireFallback(int $userId): void
    {
        $staff = $this->staffQuery()->whereKey($userId)->firstOrFail();
        $previous = [
            'responsibilities_configured' => (bool) $staff->responsibilities_configured,
            'staff_responsibilities' => $staff->staff_responsibilities,
            'legacy_profile' => $staff->employee_access_profile,
        ];

        $staff->forceFill([
            'staff_responsibilities' => $staff->staffResponsibilities(),
            'responsibilities_configured' => true,
        ])->save();

        StaffResponsibilityAudit::query()->create([
            'user_id' => $staff->id,
            'business_id' => $staff->business_id,
            'event_type' => 'legacy_fallback_retired',
            'previous_access' => $previous,
            'new_access' => [
                'responsibilities_configured' => true,
                'staff_responsibilities' => $staff->staff_responsibilities,
            ],
            'changed_by' => Auth::id(),
            'reason' => 'Owner retired legacy fallback and froze current responsibility list.',
        ]);

        $this->refreshRows();

        Notification::make()->title('Legacy fallback retired')->success()->send();
    }

    public function extendTemporaryAccess(int $assignmentId): void
    {
        $assignment = StaffResponsibilityAssignment::query()
            ->whereKey($assignmentId)
            ->whereHas('user', fn ($query) => $query->whereIn('business_id', Auth::user()?->accessibleBusinessIds() ?? []))
            ->firstOrFail();

        $assignment->forceFill([
            'expires_at' => now()->addDays(7),
            'assigned_by' => Auth::id(),
            'assignment_note' => 'Temporary access extended from migration status.',
        ])->save();

        $this->refreshRows();

        Notification::make()->title('Temporary access extended')->success()->send();
    }

    private function refreshRows(): void
    {
        $this->rows = $this->staffQuery()
            ->with(['business', 'staffResponsibilityAssignments.business'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $staff): array => $this->rowForStaff($staff))
            ->all();
    }

    private function rowForStaff(User $staff): array
    {
        $assignments = $staff->staffResponsibilityAssignments;
        $activeAssignments = $assignments->filter(fn (StaffResponsibilityAssignment $assignment): bool => $assignment->isActiveNow());
        $expiredAssignments = $assignments->filter(fn (StaffResponsibilityAssignment $assignment): bool => $assignment->expires_at?->isPast() ?? false);
        $expiringAssignments = $activeAssignments->filter(fn (StaffResponsibilityAssignment $assignment): bool => $assignment->expires_at?->between(now(), now()->addDays(3)) ?? false);
        $fallbackResponsibilities = $staff->responsibilities_configured ? [] : $staff->staffResponsibilities();
        $activeResponsibilities = $activeAssignments->pluck('responsibility_code')->merge($staff->responsibilities_configured ? (array) $staff->staff_responsibilities : [])->filter()->unique()->values();
        $lastAudit = StaffResponsibilityAudit::query()->where('user_id', $staff->id)->latest()->first();

        return [
            'id' => $staff->id,
            'name' => $staff->name,
            'email' => $staff->email,
            'business' => $staff->business?->name ?? 'No business',
            'profile' => User::employeeAccessProfileOptions()[$staff->employeeAccessProfileValue()] ?? $staff->employeeAccessProfileValue(),
            'configured' => (bool) $staff->responsibilities_configured,
            'uses_fallback' => ! (bool) $staff->responsibilities_configured && $fallbackResponsibilities !== [],
            'no_access' => (bool) $staff->responsibilities_configured && $activeResponsibilities->isEmpty(),
            'supervisor' => (bool) $staff->is_staff_supervisor || $activeResponsibilities->contains('supervisor_review'),
            'temporary_active' => $activeAssignments->contains(fn (StaffResponsibilityAssignment $assignment): bool => filled($assignment->expires_at)),
            'temporary_expiring' => $expiringAssignments->isNotEmpty(),
            'temporary_expired' => $expiredAssignments->isNotEmpty(),
            'temporary_assignment_id' => optional($expiringAssignments->first() ?: $expiredAssignments->first())->id,
            'broad_legacy_profile' => in_array($staff->employeeAccessProfileValue(), ['operations', 'finance_ops', 'full_staff'], true),
            'responsibilities' => $activeResponsibilities
                ->merge($fallbackResponsibilities)
                ->unique()
                ->map(fn (string $code): string => User::staffResponsibilityOptions()[$code] ?? $code)
                ->values()
                ->all(),
            'last_change' => optional($lastAudit?->created_at)->diffForHumans() ?? 'No audit yet',
            'action_needed' => $this->actionNeeded($staff, $activeResponsibilities->all(), $fallbackResponsibilities, $expiredAssignments->isNotEmpty()),
        ];
    }

    private function actionNeeded(User $staff, array $activeResponsibilities, array $fallbackResponsibilities, bool $hasExpired): string
    {
        if ($hasExpired) {
            return 'Review expired temporary access.';
        }

        if (! $staff->responsibilities_configured && $fallbackResponsibilities !== []) {
            return 'Configure responsibilities or retire fallback.';
        }

        if ($staff->responsibilities_configured && $activeResponsibilities === []) {
            return 'Explicit no-access. No daily missions will appear.';
        }

        return 'No immediate action.';
    }

    private function summary(): array
    {
        $rows = collect($this->rows);

        return [
            'Not configured' => $rows->where('configured', false)->count(),
            'Using fallback' => $rows->where('uses_fallback', true)->count(),
            'No access' => $rows->where('no_access', true)->count(),
            'Temporary active' => $rows->where('temporary_active', true)->count(),
            'Expiring soon' => $rows->where('temporary_expiring', true)->count(),
            'Expired temp' => $rows->where('temporary_expired', true)->count(),
            'Supervisors' => $rows->where('supervisor', true)->count(),
            'Broad legacy profile' => $rows->where('broad_legacy_profile', true)->count(),
        ];
    }

    private function staffQuery()
    {
        $user = Auth::user();

        return User::query()
            ->where('is_employee', true)
            ->when(! ($user?->isInternalAdmin() ?? false), fn ($query) => $query->whereIn('business_id', $user?->accessibleBusinessIds() ?? []));
    }
}
