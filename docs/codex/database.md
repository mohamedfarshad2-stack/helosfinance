# Database And Domain Map

This is a navigation map, not a schema dump. Code and migrations remain the source of truth.

## Business Scope

- `businesses` -> root tenant/business record; owns most operational and financial rows.
- `client_groups` -> groups related client businesses/users.
- `users` -> owner/internal admin/staff auth plus business/client-group access fields.
- `employees` -> staff salary/employment metadata.

## Financial Truth

- `operational_events` -> normalized order/operation lifecycle truth; owned by Financial Clarity and Orders.
- `financial_snapshots` -> period summaries/metrics; refreshed by snapshot services/scheduler.
- `cost_assumptions` -> operational cost rules/defaults.
- `expenses` -> manual company costs/payables/fixed/variable rows.
- `bank_transactions` -> imported/reviewed bank/cash statement rows.
- `bank_transaction_rules` -> classification helpers for bank import/review.
- `fixed_expense_templates` -> guided fixed cost setup.

## Orders And Integrations

- `integration_sources` -> Stock App connection/security/settings.
- `cod_orders` -> internal COD order source for clients not using Stock App.
- `wholesale_orders` -> Nifras-only wholesale order and profit records.
- `cod_order_sources` -> order source setup.
- `courier_rates` -> courier delivery/return/resend rates.

## Manufacturing And Inventory

- `skus` -> products/item codes and fallback product costs.
- `sku_recipe_items` -> material/labor/recipe cost lines.
- `material_components` -> raw material/component master and yield/waste data.
- `material_ledger_entries` -> material purchase/use/adjustment ledger.
- `production_entries` -> daily production, piece work, payout, part/WIP records.
- `production_work_steps` -> setup for production labor steps.
- `sku_stock_movements` -> stock movement effects from production/order lifecycle.

## Service Billing

- `service_clients` -> service customer master.
- `service_billing_records` -> billed/due/paid service income rows.

## Missions And Responsibility Access

- `staff_responsibility_assignments` -> business-scoped staff permissions by responsibility.
- `staff_responsibility_audits` -> responsibility change history.
- `missions` -> persistent work generated from business conditions/manual owner tasks.
- `mission_events` -> mission lifecycle/audit trail.

## Analytics

- `website_analytics_events` -> public tracker events used by Website Insights.

## Shared Tables

- `operational_events` is the most shared table: Stock App/COD writes it; financial dashboards, SKU repair, trust validation, work queue, revenue pipeline, and stock movement read it.
- `businesses` and `users` are shared by almost every module.
- `skus` bridges orders, manufacturing, stock, and profitability.
- `expenses` and `bank_transactions` bridge cash, company costs, profit, and missions.
