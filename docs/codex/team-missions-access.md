# Team, Missions, And Access

## Purpose

Controls owner/internal-admin/staff access, business scoping, responsibility assignment, mission generation, Today's Work, supervisor review, and employee role dashboards.

## Entry Points

- `app/Filament/Pages/TodaysWork.php`
- `app/Filament/Pages/ManagerWorkQueue.php`
- `app/Filament/Pages/SandhamaliAccount.php`
- `app/Filament/Pages/LegacyAccessMigrationStatus.php`
- `app/Filament/Resources/UserResource.php`
- `app/Filament/Resources/EmployeeResource.php`
- `app/Filament/Resources/StaffResponsibilityAssignmentResource.php`
- `app/Filament/Resources/StaffResponsibilityAuditResource.php`
- `app/Filament/Resources/MissionResource.php`

## Core Files

- `app/Models/User.php`
- `app/Domains/Shared/Services/WorkQueueService.php`
- `app/Domains/Shared/Services/MissionGeneratorService.php`
- `app/Domains/Shared/Services/MissionSourceActionService.php`
- `app/Domains/Shared/Models/Mission.php`
- `app/Domains/Shared/Models/MissionEvent.php`
- `app/Domains/Shared/Models/StaffResponsibilityAssignment.php`
- `app/Domains/Shared/Models/StaffResponsibilityAudit.php`

## Main Data Models

- `User`, `Employee`, `StaffResponsibilityAssignment`, `StaffResponsibilityAudit`, `Mission`, `MissionEvent`, `Business`.

## Database Tables

- `users`, `employees`, `staff_responsibility_assignments`, `staff_responsibility_audits`, `missions`, `mission_events`, `businesses`, `client_groups`.

## Business Flow

Owner/internal admin creates users -> assigns business-scoped responsibilities -> work queue detects real business conditions -> missions are generated/assigned -> staff uses Today's Work -> supervisor/owner reviews, reassigns, approves, escalates, or completes source-backed missions.

## Important Business Rules

- Staff access is responsibility-scoped and business-scoped.
- Sandhamali has a dedicated account workspace and should not land on the generic staff board.
- Owners/internal admins retain setup and sensitive visibility.
- Supervisor review does not automatically equal owner finance access.
- Mission completion should depend on the source condition being resolved when source-backed.
- Sensitive bank decisions are owner-only.
- Legacy access fallbacks exist but should not bypass direct authorization checks.

## Dependencies

All modules; this layer maps operational/finance conditions into employee work.

## Where To Start For Common Changes

- Employee role preset/access form -> `UserResource`.
- Staff dashboard/parcel movement -> `TodaysWork`.
- Sandhamali service workspace -> `SandhamaliAccount`.
- Work queue task generation -> `WorkQueueService`.
- Persistent missions -> `MissionGeneratorService`.
- Inline mission actions -> `MissionSourceActionService`.
- Supervisor/owner review -> `MissionResource`, `ManagerWorkQueue`.
- Responsibility audit/history -> responsibility resources and models.

## Testing

- `php artisan test tests/Feature/UserResourceTest.php`
- `php artisan test tests/Feature/ResponsibilityMissionSystemTest.php`
- `php artisan test tests/Feature/WorkQueueIntelligenceTest.php`
- `php artisan test tests/Feature/BusinessScopeSecurityTest.php`
- `php artisan test tests/Feature/ProductionTeamProvisioningTest.php`
