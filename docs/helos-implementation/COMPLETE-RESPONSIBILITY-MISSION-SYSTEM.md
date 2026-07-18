# HELOS Complete Responsibility Mission System

Status: Implemented with known limitations
Date: 2026-07-18
Branch: `helosfinance`

## Completed

### Responsibility Access

- Business-scoped staff responsibilities are implemented.
- Temporary access is supported with expiry.
- Explicit no-access is supported by `responsibilities_configured = true` and no active responsibilities.
- Expired assignments stop granting access.
- Responsibility changes are audited.
- Legacy profile fallback remains only for staff not yet explicitly configured.

### Persistent Missions

- Missions are generated from real HELOS work conditions through `WorkQueueService`.
- Missions are persisted with source key, source type, source id, business, responsibility, priority, status, impact, and confidence.
- Mission lifecycle events are recorded for generation, start, source action, completion, blocking, escalation, reassignment, approval, and return for correction.
- Duplicate unresolved missions are prevented by `source_key`.
- Source conditions disappearing cancel active missions.

### Inline Source Actions

Implemented source actions update real HELOS-owned records:

- Bank exceptions: classification, money effect, business allocation, container, transfer destination, note.
- Service collections: paid amount, payment status, method, reference, note.
- Expenses: supplier/payee, due date, paid amount, payment status, method, note.
- Production payouts: payment status, paid date, note.
- Material stock repair: SKU link, note.
- Product/SKU repair on operational events: SKU link and event recalculation through existing recalculation service.
- Return/resend operational notes: return outcome, reason, follow-up note.
- Internal COD order fields: tracking, courier, status, return/resend reason, note.

Source-action behavior:

- The source record is updated first.
- A mission event is recorded.
- HELOS re-checks whether the mission is resolved.
- Mission is auto-completed only when the source condition is resolved.
- Otherwise the mission remains open/in progress or goes to waiting review.

### Protected Decisions

- Staff cannot approve owner-only bank classifications such as owner withdrawals, owner contributions, and loans.
- Owner-only bank decisions are submitted to waiting review.
- Manual mission completion is blocked when the source condition is still unresolved.

### Today’s Work

Today’s Work now includes:

- Today’s Priority.
- Today’s Missions.
- Problems.
- Missing Information.
- Waiting Review.
- Completed Today.
- Business impact on each mission.

Employees can start, do source action, check completion, block, escalate, or open the full source record when needed.

### Supervisor Workflow

Mission Review supports:

- Filtering by status and responsibility.
- Reassigning missions.
- Returning missions for correction with a reason.
- Approving missions.
- Escalating missions to the owner.

Supervisor actions write mission events.

### Legacy Migration Status

Owner/internal admin can see:

- Explicitly configured staff.
- Not configured staff.
- Staff using legacy fallback.
- Explicit no-access staff.
- Temporary access active.
- Temporary access expiring soon.
- Temporary access expired.
- Supervisor enabled.
- Broad legacy profile still active.

Owner actions:

- Remove legacy fallback for one employee.
- Assign explicit no-access.
- Extend temporary access by seven days.
- Open responsibility manager.
- Open access audit history.

### Navigation

Navigation is partially consolidated:

- Staff mission work is under `My Work`.
- Supervisor mission review is under `Team Work`.
- Responsibility setup and audit history are under `Team`.
- Sales Insights and Operational Event History are under `Reports`.

Existing resources were not deleted, to avoid blocking production work during transition.

### Profit-Impact Priority

Mission ranking now uses trusted source values where available:

- Expense balance.
- Service billing balance.
- Wholesale collection balance.
- Other work-queue amounts already emitted by HELOS.

HELOS does not fabricate values when source impact is missing. Missing impact is marked as incomplete.

## Partial Or Pending

- Full dispatch action for Stock App orders remains partial because HELOS must not mutate Stock App truth from inside HELOS.
- Full production output creation from a mission is not implemented where no source production row exists yet.
- Full material purchase creation from a mission is not implemented where no source ledger row exists yet.
- Full employee-only navigation rewrite is partial; legacy resources remain available where existing authorization permits them.
- Global fallback retirement setting is not implemented. Current safe retirement is per employee.
- Browser-based production smoke verification was not performed in this document.

## Security

Implemented protections:

- Mission actions check business scope.
- Staff source actions require matching active responsibility with `can_complete`.
- Owner/internal admin retain full access.
- Supervisor review does not grant owner financial pages by itself.
- Explicit no-access staff do not receive legacy fallback.

Remaining security work:

- Broader direct-URL tests for every legacy resource.
- Final production role smoke testing with real staff accounts.

## Tests

Focused coverage added for:

- Collection action updates billing and completes mission.
- Staff cannot approve owner-only bank money type.
- Unresolved mission cannot be completed by button click.
- Legacy migration page identifies fallback and explicit no-access users.
- Higher trusted impact ranks first.

Focused test command:

```bash
php artisan test --filter=ResponsibilityMissionSystemTest
```

Latest focused result:

- 11 tests passed.
- 35 assertions passed.

## Deployment

Repository deployment workflow:

- Pushes to `helosfinance` run GitHub Actions.
- Checks run PHP tests, npm audit, and frontend build.
- On successful push, the deploy job SSHes to Lightsail and runs:

```bash
sudo deploy-helosfinance helosfinance
```

Production deployment must be verified from GitHub Actions and the hosted site after push.

## Rollback

Low-risk rollback:

- Revert the mission action/navigation commits.
- Run deployment workflow again.

Data rollback:

- Responsibility and mission tables are additive.
- Mission source actions change source records. If a production source action is wrong, use mission event history to identify the source row, user, timestamp, and before/after values.

## Known Limitations

- HELOS-owned source records can be updated inline.
- External Stock App order truth is not directly changed from HELOS.
- Some mission actions still need the full source record page for uncommon fields.
- Production browser verification remains required.
