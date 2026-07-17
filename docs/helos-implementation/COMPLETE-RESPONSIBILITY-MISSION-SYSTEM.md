# HELOS Complete Responsibility Mission System

Status: Implemented foundation with documented limits
Date: 2026-07-17
Branch: `helosfinance`

## 1. Architecture

HELOS now has a persistent responsibility and mission layer on top of the existing business logic.

The implementation keeps existing financial engines, Stock App sync, operational event calculations, expense calculations, bank calculations, production calculations, and owner dashboards unchanged.

Flow:

Business condition -> WorkQueueService task -> MissionGeneratorService -> Mission -> assigned responsibility -> assigned employee -> Today&apos;s work -> mission event history -> supervisor/owner review.

## 2. Responsibility Model

Responsibilities are still defined centrally through `App\Models\User::staffResponsibilityOptions()`.

The new table `staff_responsibility_assignments` allows the owner to assign:

- staff member
- business
- responsibility
- view/create/edit/complete/review/approve flags
- own-record/team-record flags
- active status
- optional start date
- optional expiry date
- assignment note

Existing JSON-based staff responsibilities remain as a legacy fallback for users not fully migrated.

## 3. Business Scoping

Business scoping is enforced through `User::accessibleBusinessIdsForResponsibility()` and resource query filters.

Staff access can now be limited to one business for one responsibility. Example: a user can have expense work for Horns England without receiving expense work for COD Returns Lanka.

Resources updated to use responsibility-aware business scopes include:

- COD orders
- service clients
- service billing
- production entries
- material ledger
- material components
- production work steps
- bank transactions
- expenses

Owner and platform admin access remains broad according to the existing access model.

## 4. Mission Model

The `missions` table stores persistent work items generated from real HELOS conditions.

Mission states:

- open
- in progress
- blocked
- waiting review
- completed
- escalated
- cancelled
- reopened

Each mission stores source type/id, business, responsibility, priority, impact type, estimated impact, confidence, due date, assigned user, and metadata.

Duplicate missions are prevented with a unique `source_key`.

## 5. Mission Generators

`MissionGeneratorService` currently converts existing `WorkQueueService` tasks into persistent missions.

This means the new system is based on existing real HELOS conditions such as:

- order tracking problems
- returns/resends
- service collections
- bank exceptions
- expense settlement
- production payout
- material/stock issues
- missing product links

The generator also assigns missions to the first active staff responsibility assignment for that business and responsibility.

## 6. Direct Mission Actions

Implemented:

- start mission
- complete mission
- mark blocked
- escalate
- open related record through existing resource URL

Not fully implemented yet:

- focused slide-over/modal editing for every mission type
- automatic completion when the underlying source record is fixed
- direct inline tracking/courier/expense/payment/product-mapping forms inside Today&apos;s Work

The current implementation intentionally reuses existing resources for source-record editing to avoid creating a second source of truth.

## 7. Completion History

The `mission_events` table records:

- status transitions
- who changed the mission
- previous values
- new values
- notes
- timestamps

Mission completion, block, escalation, approval, reopening, and manual edits create history.

## 8. Supervisor Workflow

`MissionResource` provides a supervisor/owner review surface.

Supervisors with the `supervisor_review` responsibility can access mission review for their scoped businesses without automatically receiving owner financial guidance.

Available supervisor actions:

- edit mission
- approve/complete
- return/reopen
- escalate
- refresh generated missions

## 9. Owner Workflow

Owner-only responsibility management is available through:

- `StaffResponsibilityAssignmentResource`
- `StaffResponsibilityAuditResource`

The owner can assign, remove, scope, expire, and audit employee responsibility access.

## 10. Navigation Structure

Implemented foundation:

- staff continue landing on Today&apos;s Work
- staff see work based on responsibility scope
- responsibility manager appears under Admin for owner/internal admin
- mission review appears under Work for owner/internal admin/supervisor
- existing owner pages remain accessible

Not fully completed:

- full owner navigation regrouping into Command Centre / Operations / Finance Control / Team / Configuration / Reports
- full employee-only navigation reduction across every legacy page
- visual legacy migration status screen

## 11. Page Consolidation Map

The implementation preserves existing pages and begins consolidation through access scope rather than deleting pages.

Current state:

- Today&apos;s Work is the primary employee mission layer.
- Existing resources remain the source-action pages.
- Mission Review is the supervisor/owner review surface.
- Responsibility and Access History are owner/internal-admin setup/audit pages.

No legacy page was deleted.

## 12. Priority Scoring

Implemented:

- mission priority from existing Work Queue priority
- impact categories:
  - revenue protected
  - revenue recoverable
  - cash collectible
  - cost avoidable
  - financial truth blocked

Not fully implemented:

- detailed trusted-profit impact scoring for every mission
- owner-visible priority formula
- confidence downgrade rules for every mission type

## 13. Security Controls

Implemented and tested:

- business-scoped responsibility access
- expired responsibility stops access
- responsibility audit history
- staff cannot manage responsibilities
- supervisor can review missions without owner finance access
- owner/internal admin access preserved
- query scopes for responsibility-aware resources

Security principle: navigation hiding is not trusted by itself. Query-level and page-level access are used.

## 14. Legacy Migration

Existing staff responsibility JSON remains as fallback for users who are not fully migrated.

Configured staff with no responsibilities get no work access.

Future step: create owner-visible migration status before disabling legacy fallback globally.

## 15. Tests

New tests:

- business-scoped responsibility limits resource query scope
- temporary responsibility expiry removes access
- assignment changes are audited
- missions generate from real conditions
- missions respect staff scope
- mission completion writes history
- supervisor can review missions without owner finance access
- staff cannot access responsibility manager

Regression tests also confirm existing user-resource and work-queue behavior.

## 16. Deployment Steps

Recommended production steps:

1. Pull/push deployed branch.
2. Back up production database.
3. Run `php artisan migrate`.
4. Run `php artisan optimize:clear`.
5. Verify owner can open Responsibilities and Access History.
6. Verify staff Today&apos;s Work still opens.
7. Assign one test responsibility to one test staff user for one business.
8. Confirm direct URLs are denied outside that business/responsibility.

## 17. Rollback Steps

Code rollback:

- revert the responsibility/mission commit.

Database rollback:

- restore production backup if mission/assignment tables have production data.
- avoid blindly rolling back after owner has created live assignments unless the backup is confirmed.

Low-risk rollback path:

- leave new tables in place but remove navigation/access to new resources.
- existing legacy responsibility behavior remains available.

## 18. Known Limitations

- Mission completion marks the mission complete; it does not yet always update the underlying source record.
- If the unresolved source condition still exists, mission generation may recreate or reopen work in a later sync.
- Direct source actions are currently links plus mission status actions, not full inline mission workflows.
- Separation-of-duty warnings are not fully implemented.
- Owner navigation is not fully regrouped yet.
- Legacy fallback retirement status screen is not implemented yet.
- Full production browser verification was not completed in this document.

## 19. Future Improvements

Next implementation steps:

1. Build direct mission action forms for the highest-volume tasks: tracking/courier, bank exception, expense settlement, service payment, product mapping.
2. Auto-complete missions when the source condition is truly resolved.
3. Add owner-visible legacy migration status.
4. Add separation-of-duty warnings.
5. Complete navigation regrouping after mission actions cover daily work.
6. Add production smoke-test checklist to deployment process.
