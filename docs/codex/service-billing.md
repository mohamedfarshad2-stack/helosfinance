# Service Billing

## Purpose

Supports service businesses or service income streams: clients, billing periods, amounts due/paid, collection status, due dates, payment references, and work queue signals.

## Entry Points

- `app/Filament/Resources/ServiceClientResource.php`
- `app/Filament/Resources/ServiceBillingResource.php`
- `app/Filament/Pages/SandhamaliAccount.php`

## Core Files

- `app/Domains/Shared/Models/ServiceClient.php`
- `app/Domains/Shared/Models/ServiceBillingRecord.php`
- `app/Filament/Resources/ServiceClientResource.php`
- `app/Filament/Resources/ServiceBillingResource.php`
- `app/Domains/FinancialClarity/Services/RevenuePipelineService.php`
- `app/Domains/Shared/Services/WorkQueueService.php`

## Main Data Models

- `ServiceClient`, `ServiceBillingRecord`, `Business`, `Mission`.
- `ServiceClient`, `ServiceBillingRecord`, `ServiceLead`, `Business`, `Mission`.

## Database Tables

- `service_clients`, `service_billing_records`.
- `service_clients`, `service_billing_records`, `service_leads`.

## Business Flow

Service client setup -> billing record with period/due/payment status -> paid/balance due calculation -> revenue/cash/work queue/dashboard signals.
Sandhamali service workspace -> service lead capture/import -> lead conversion into service clients -> automatic first billing row for the client -> recurring payment reminders.
Sandhamali account now keeps its visible active-client list tied to billing-backed live truth and clears orphan manual client rows so the page does not keep showing owner-added test clients.
Sandhamali now can live-sync service clients and pricing rows from Stock App admin pages into HELOS so the active-client list reflects the current Stock App truth instead of manual test rows.
Sandhamali now shows the Stock App sync result on the page and only clears orphan client rows after a successful live sync, so a failed read does not blank the visible client list.

## Important Business Rules

- Duplicate service client names are validated per business.
- Owners may create service clients across allowed client-group businesses.
- Billing feeds service revenue and collections but should not be mixed with COD order lifecycle revenue.
- Live Stock App service-client sync should only read from the configured read-only Stock App admin pages and should not mutate Stock App data.

## Dependencies

Financial Clarity, Bank/Expenses, Team/Missions, Business module visibility.

## Where To Start For Common Changes

- Service client setup -> `ServiceClientResource`.
- Billing/payment/balance issue -> `ServiceBillingResource`, `ServiceBillingRecord`.
- Sandhamali service workspace and lead funnel -> `SandhamaliAccount`, `ServiceLead`, `ServiceLeadSpreadsheetImportService`, `ServiceLeadTemplateExportService`.
- Service revenue dashboard issue -> `RevenuePipelineService`, `BusinessHealthSnapshotService`.
- Collection work queue -> `WorkQueueService`, `MissionSourceActionService`.

## Testing

- `php artisan test tests/Feature/ServiceClientResourceTest.php`
- `php artisan test tests/Feature/ServiceBusinessBillingIntegrationTest.php`
- `php artisan test tests/Feature/BusinessTypeModuleVisibilityTest.php`
