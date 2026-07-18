# HELOS Page And Navigation Consolidation

Status: Partially implemented
Date: 2026-07-18
Code changes: Responsibility-scoped access, persistent missions, inline source actions, supervisor mission review, legacy migration status, and owner responsibility audit pages implemented
Database changes: Responsibility assignment, responsibility audit, mission, and mission event tables added
Deployment: Not verified in this document

## Implemented Status Update

VERIFIED: The first implementation stage has been completed as a foundation.

Implemented:

- Business-scoped responsibility assignment table.
- Temporary access with expiry.
- Responsibility audit history.
- Mission table and mission event history.
- Mission generation from existing real `WorkQueueService` business conditions.
- Today&apos;s Work now reads persistent missions instead of only transient work-queue arrays.
- Staff mission visibility is filtered by assigned responsibility and business.
- Owner/internal-admin responsibility manager.
- Owner/internal-admin responsibility access history.
- Supervisor/owner mission review resource.
- Query scoping for key resources by responsibility-specific business access.
- Focused inline mission actions for HELOS-owned source records.
- Auto-completion only after the source condition is resolved.
- Owner-only protection for sensitive bank decisions.
- Legacy Migration Status page for owner/internal admin.
- Mission Review reassignment, return, approval, escalation, and filters.
- Mission priority ranking using available trusted source amounts.

Partially implemented:

- Direct mission actions. Staff can update core HELOS-owned source records from mission flow. External Stock App truth is not changed from HELOS.
- Navigation consolidation. Staff work is mission-first, but all legacy navigation groups have not yet been fully hidden because production transition safety requires fallback pages.
- Supervisor workspace. Mission review exists, but a full Team Work / Reviews / Escalations workspace is not yet complete.
- Legacy fallback retirement. Per-employee safe retirement exists; global fallback shutdown is not implemented.

Not implemented yet:

- Full owner Command Centre navigation regrouping.
- Employee-only My Work / My Responsibilities / Help navigation rewrite.
- Separation-of-duty conflict warnings.
- Global legacy fallback shutdown switch.
- Inline source creation where no source record exists yet.

## Purpose

This document maps the current HELOS Filament pages and resources into a mission-centred navigation model.

The goal is not to add another dashboard on top of the existing scattered system. The goal is to make the mission layer the normal operating layer for employees, while existing pages become focused action screens, work lists, supervisor reviews, owner decisions, setup, reports, history, or deprecated legacy screens.

## Current Navigation Structure

VERIFIED from source code, HELOS currently exposes these main navigation groups:

- HELOS
- Money
- Setup
- Sales & Work
- Products & Production
- Admin
- Work
- Analytics

Current issue: these groups mix daily work, setup, reports, repair screens, owner decisions, and admin controls. A zero-finance employee can be sent into broad modules instead of receiving a clear mission.

## Page Consolidation Map

| Existing Page | Current Purpose | Current Users | Future Category | Responsibility | Keep/Merge/Hide/Deprecate | Future Entry Point |
| ------------- | --------------- | ------------- | --------------- | -------------- | ------------------------- | ------------------ |
| Owner Home / Client Health Report | Owner business command view | Owner | Owner Decision | Owner | Keep standalone | Owner Command Centre |
| Sales Insights | Sales, dispatch, delivered, profit signal view | Owner/internal admin | Owner Decision / Report | Owner | Keep, simplify as owner report | Owner Command Centre > Sales |
| Today's Work | Staff task dashboard | Staff | Employee Work List | Assigned staff | Keep, rebuild as primary mission layer | My Work > Today |
| Owner Work Queue / ManagerWorkQueue | Owner work queue/team workload | Owner | Supervisor Review | Owner/supervisor | Merge into supervisor/owner approvals | Owner Command Centre > Team Missions |
| Dashboard | Redirect/Filament default | System | Duplicate Or Obsolete | System | Deprecate as user-facing page | Route users to Owner Home or My Work |
| Bank Statement Import | Upload bank statement | Owner/finance ops | Mission Action / Setup-adjacent | Admin/collections | Keep as contextual action | Bank Exceptions mission or Finance Control |
| Bank Review | Classify bank/cash rows | Owner/finance ops | Employee Mission Action / Supervisor Review | Collections/admin | Keep, hide broad navigation for normal staff | Bank exception missions |
| Bank Rules | Rule setup for bank matching | Owner/internal admin | Setup And Configuration | Owner/admin | Keep standalone | Configuration > Bank Rules |
| Petty Cash Spend | Quick expense entry | Owner/finance ops | Employee Mission Action | Purchaser/admin | Merge into expense mission flow | Expense mission > Record spend |
| Expenses & Payables | Expense/payable records | Owner/finance ops | Employee Mission Action / Supervisor Review | Purchaser/admin/supervisor | Keep as resource, hide from unrelated staff | Expense and supplier missions |
| Expense Templates | Fixed expense setup | Owner | Setup And Configuration | Owner | Keep standalone | Configuration > Fixed Costs |
| Business Setup | Business profile/module setup | Owner/internal admin | Setup And Configuration | Owner/admin | Keep standalone | Configuration > Business |
| New Client Wizard | Super admin client onboarding | Internal admin | Setup And Configuration | Platform admin | Keep standalone | Admin > New Client |
| Team Access | User account/access creation | Owner/internal admin | Setup And Configuration | Owner/admin | Keep standalone | Team > Access |
| Staff & Salary Setup | Employee master and salaries | Owner/internal admin | Setup And Configuration / Owner Decision | Owner | Keep standalone | Team > Staff & Salary |
| Stock App Link | Integration source setup | Owner/internal admin | Setup And Configuration | Owner/admin | Keep standalone | Configuration > Integrations |
| COD Orders Workbench | Upload/manage HELOS internal COD orders | Owner/ops/finance staff | Employee Work List / Mission Action | CSR/dispatch | Keep as workbench; mission-first entry | Order confirmation / dispatch missions |
| COD Orders Resource | CRUD/action backend for COD orders | Owner/ops/finance staff | Employee Mission Action | CSR/dispatch/returns | Merge behind workbench/missions | Mission detail/action modal |
| COD Order Sources | Order source setup | Owner/admin | Setup And Configuration | Owner/admin | Keep standalone | Configuration > Sales Sources |
| Stock App Order Events / Operational Events | Historical event stream | Owner/internal admin | Reports And History | Owner/auditor | Keep as history/report, hide from staff | Reports > Event History |
| Fix Missing Product Links | Repair SKU mapping from events | Owner/admin/ops staff | Employee Mission Action / Work List | Product/operations | Keep, mission-filtered | Product repair missions |
| Products / SKUs | Product master and cost fallback | Owner/internal admin | Setup And Configuration | Owner/product lead | Keep standalone owner setup | Configuration > Products |
| Product Cost Recipes | SKU cost recipe lines | Owner/internal admin | Setup And Configuration | Owner/product lead | Keep standalone | Configuration > Product Costs |
| Raw Material Components | Material component setup | Owner/ops/finance staff | Setup And Configuration | Owner/store lead | Keep standalone, owner/supervisor only | Configuration > Materials |
| Raw Material Stock | Material purchase/use ledger | Owner/ops/finance staff | Employee Mission Action / Work List | Store/purchasing | Keep as action backend; mission-first | Material purchase/use missions |
| Labour Work Types | Production work step setup | Owner/ops/finance staff | Setup And Configuration | Owner/production supervisor | Keep standalone | Configuration > Labour Work |
| Production & Piece Pay | Daily production and weekly pay | Owner/ops/finance staff | Employee Mission Action / Supervisor Review | Production/supervisor | Keep as mission action backend | Production missions / pay review |
| Courier Charges | Courier delivery/return/resend rates | Owner/admin | Setup And Configuration | Owner | Keep standalone | Configuration > Courier Rates |
| Other Cost Rules | Generic operational assumptions | Owner/internal admin | Setup And Configuration | Owner | Keep but review duplication with courier rates | Configuration > Cost Rules |
| Service Clients | Service client master | Owner/finance ops | Setup And Configuration / Mission Action | Collections/admin | Keep; mission for client updates | Service collection missions / setup |
| Service Income | Service billing/payment rows | Owner/finance ops | Employee Mission Action / Work List | Collections/admin | Keep as action backend | Collection missions |
| Website Insights | Website analytics report | Owner/internal admin | Reports And History | Owner/marketing | Keep as report only if business-relevant | Reports > Website |
| Material Ledger create/edit pages | Create/edit material entries | Resource subpages | Employee Mission Action | Store/purchasing | Keep behind missions | Material mission action |
| Expense create/edit pages | Create/edit expense entries | Resource subpages | Employee Mission Action | Purchaser/admin | Keep behind missions | Expense mission action |
| Bank transaction create/edit pages | Review/edit bank rows | Resource subpages | Employee Mission Action / Supervisor Review | Admin/supervisor | Keep behind missions | Bank exception mission |
| Production create/edit pages | Record/edit production entries | Resource subpages | Employee Mission Action | Production/supervisor | Keep behind missions | Production mission action |
| Service billing create/edit pages | Update collection rows | Resource subpages | Employee Mission Action | Collections/admin | Keep behind missions | Collection mission action |

## Proposed Owner Navigation

### Command Centre

- Today's Business Position
- Profit Opportunities
- Cash Risks
- Critical Problems
- Team Missions
- Owner Approvals

### Operations

- Sales and COD
- Returns
- Production
- Inventory
- Purchasing
- Service Business

### Finance Control

- Expenses and Payables
- Bank and Cash
- Collections
- Payroll and Piece Pay
- Reconciliation
- Financial Trust

### Team

- Staff and Salary
- Responsibilities
- Mission Performance
- Escalations
- Access History

### Configuration

- Business Setup
- Products and Costs
- Material Components
- Labour Work Types
- Courier Rates
- Cost Rules
- Bank Rules
- Sales Sources
- Integrations

### Reports

- Sales Insights
- Operational Event History
- Product Contribution
- Cash Flow
- Business Performance
- Website Insights
- Audit History

## Proposed Employee Navigation

Normal employee navigation should be small:

### My Work

- Today
- Upcoming
- Problems
- Completed
- Escalations

### My Responsibilities

Only show assigned responsibilities:

- Order Confirmation
- Dispatch
- Return Recovery
- Production
- Material Stock
- Expenses
- Collections
- Bank Exceptions

### Help

- How to complete my tasks
- Report a problem

Employees should not see owner reports, broad finance menus, setup screens, or unrelated operational resources.

## Proposed Supervisor Navigation

### Team Work

- Today's Team Missions
- Overdue Work
- Blocked Work
- Reassign Work
- Completion by Responsibility

### Reviews

- Production Pay Review
- Bank Exceptions
- Supplier Due Review
- Product Mapping Exceptions
- Return/Resend Exceptions

### Escalate

- Send to Owner
- Ask for Missing Rule
- Mark as Cannot Complete

Supervisors should not automatically see owner cash, safe withdrawal, growth capacity, or strategic finance guidance unless they are also owner-level users.

## Duplicate Pages

The following are not necessarily wrong, but they compete for the same work:

1. `Today's Work` and `Owner Work Queue`: same work-queue source, different audiences.
2. `COD Orders Workbench` and `COD Orders Resource`: same operational object; workbench should become the staff-facing list, resource should become backend/action surface.
3. `Petty Cash Spend` and `Expenses & Payables`: both create expense truth; quick page should become a focused mission action.
4. `Courier Charges` and `Other Cost Rules`: courier-specific costs should not be duplicated in generic assumptions.
5. `Raw Material Stock` and `Expenses & Payables`: material purchase can affect both stock and payable; one source flow is needed.
6. `Service Clients` and `Service Income`: client setup and payment collection are separate, but monthly collection missions should prevent users jumping between both.
7. `Stock App Order Events` and `Sales Insights`: event stream is history; Sales Insights is owner interpretation. Staff should not use event history as a work finder.
8. `Fix Missing Product Links` and SKU setup pages: repair should be mission/action; product setup remains owner/product lead configuration.

## Obsolete Or Candidate Legacy Pages

Do not delete now. Mark these as transition candidates:

- Default `Dashboard`: replace with role-based landing redirect.
- Broad resource index pages for staff: keep backend but hide from normal navigation where mission actions exist.
- Full `Operational Events` list for normal users: owner/audit report only.
- Quick entry pages that duplicate resource actions: keep until mission actions replace them.
- Old work queues that do not respect responsibility assignment: deprecate after supervisor workspace exists.

## Pages That Should Become Mission Actions

- Add tracking number.
- Confirm courier.
- Classify return as restock/damaged/resend.
- Record production output.
- Mark production pay as paid.
- Record material purchase/use.
- Add supplier/payee/due date for expense.
- Review bank exception.
- Match bank row to existing expense.
- Update service client payment.
- Fix missing product link.
- Confirm marketing spend or confirm no spend.

## Pages That Should Remain Standalone

- Owner Home / Client Health Report.
- Sales Insights, as owner/report page.
- Business Setup.
- Team Access.
- Staff & Salary Setup.
- Products / SKUs.
- Product Cost Recipes.
- Courier Charges.
- Bank Rules.
- Stock App Link.
- Expense Templates.
- Website Insights, if retained as a report.
- Operational Events, as audit/history only.

## Migration Risks

1. Hiding pages too early can block work before mission actions are complete.
2. Duplicating forms inside a dashboard can create two sources of logic and validation.
3. Broad staff profiles may continue to expose pages through direct URLs.
4. Existing owner workflows may depend on current resource pages.
5. Reports may still be used as work lists until missions cover the same findings.
6. Staff may lose access to a needed action if responsibility mapping is wrong.
7. Direct URLs must enforce backend permissions, not just navigation visibility.
8. Production users need a transition period where old and new flows can be compared.

## Rollback Approach

1. Stage navigation changes behind responsibility checks.
2. Keep existing resources available to owners/internal admins during transition.
3. First hide pages only from normal staff navigation, not from owners.
4. Do not delete resources until production usage is verified.
5. Keep mission actions using existing resource validation/services.
6. Log or track which mission completed which record before deprecating old flows.
7. If a mission flow fails, restore the old resource link for that responsibility while keeping owner navigation unchanged.

## Recommended Transition

### Stage 1

- Add responsibility assignments.
- Make `Today's Work` the employee landing page.
- Filter employee navigation by responsibility, not broad legacy profiles.
- Link missions to filtered existing resources.
- Preserve owner navigation.

### Stage 2

- Add direct mission actions for common work.
- Group employee work by responsibility.
- Hide irrelevant legacy pages from staff.
- Create supervisor workspace.

### Stage 3

- Merge duplicate quick-entry flows.
- Move setup pages to Configuration.
- Move reports/history to Reports.
- Deprecate old broad work queues.
- Remove obsolete workflows only after production verification.

## Completion Standard

This consolidation is complete only when:

- Employees no longer search through unrelated modules.
- Employees see only relevant responsibilities.
- Common actions can be completed from mission flow.
- Setup is separate from daily work.
- Reports are separate from action pages.
- Owner decisions remain in the command centre.
- Supervisor reviews are separate from normal employee tasks.
- Duplicate pages are hidden or controlled.
- Old pages cannot bypass authorization.
- Existing business logic is reused, not copied.
- Navigation becomes smaller after implementation.

## Final Recommendation

Do not build more pages first. Convert the existing pages into a mission-backed operating layer:

- Mission dashboard for employees.
- Review workspace for supervisors.
- Command centre for owner.
- Configuration for setup.
- Reports/history for understanding.

Every current page should either serve a mission, support a supervisor review, support an owner decision, configure the system, explain history, or be deprecated.
