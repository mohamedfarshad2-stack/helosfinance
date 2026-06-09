# HELOS V1

HELOS is an Operational Financial Clarity System.

It converts operational activity into simple business visibility: estimated profit, leakage, COD pressure, SKU profitability, department pressure, and operational cost impact.

HELOS is not an ERP, accounting system, stock system, CRM, payroll tool, tax tool, or AI prediction engine.

## Local Stack

- Laravel 12
- Filament v3
- MySQL through XAMPP
- Queue-ready Laravel database queue
- API-ready stock-app sync endpoints

## Local Setup

```bash
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=9001
```

Admin panel:

```text
http://127.0.0.1:9001/admin
```

Demo login:

```text
admin@helos.local
password
```

The default database is `helos_v1` on local XAMPP MySQL with user `root` and no password.

## V1 Modules

- Business Health: visible revenue, costs, leakage, and estimated operational profit
- Revenue: revenue by operational event, SKU, and channel
- Costs: operational costs and manual overhead inputs
- Leakage: returns, fake orders, resends, waste, and recovery
- Operations Impact: event-based financial behavior from stock-app activity
- Manufacturing: SKU cost components and production cost visibility
- COD Impact: delivered, returned, resent, fake order, courier, verification, and recovery impact
- Input Center: assumptions, overheads, integrations, and manual adjustments
- Reports: currently represented through snapshots and dashboard widgets
- Integrations: local stock-app webhook and sync endpoints

## SKU Bulk Upload

Go to `Manufacturing > SKU Profitability`.

Use `Download sample` to get the Excel template, then use `Upload Excel` to import or update SKUs in bulk.

Required columns:

```text
code
name
material_cost
packaging_cost
labor_rate
finishing_cost
expected_sale_price
active
```

The upload matches SKUs by business and `code`. Existing SKU codes are updated; new SKU codes are created.

## Cost Behavior

Operational costs now carry a cost behavior:

- `Fixed cost`: rent, base salaries, subscriptions, electricity minimums, and other costs that create pressure even when order volume is low.
- `Variable cost`: courier cost, packaging usage, fuel per dispatch, production-linked labor, and other costs that move with operations.

This split is included in the business health calculation so estimated profit is not based only on event costs.

## Client Setup

HELOS is built for multiple client businesses.

Use `Input Center > Client Businesses` to create or update each client business. A business can track its own industry, currency, clarity start date, and onboarding stage.

Use `Costs > Operational Costs > Add guided fixed costs` when a client does not know what fixed expenses to enter. HELOS creates plain-language rows such as rent, base salaries, electricity, internet and phone, software tools, loan repayments, and owner drawings with zero amounts. The client can then fill only the amounts that apply.

After the client confirms a fixed expense, use `Lock`. Locked fixed expenses stay part of clarity calculations and cannot be edited casually. Platform admins can unlock them when the client confirms a correction.

Platform admins can maintain the suggestion list in `Input Center > Fixed Expense Guide`.

## API Testing

Delivered order:

```powershell
$body = @{
    event_type = 'order_delivered'
    external_id = 'ORDER-100'
    sku_code = 'BAG-CLASSIC'
    sale_amount = 3200
    quantity = 1
    channel = 'COD'
} | ConvertTo-Json

Invoke-RestMethod -Uri http://127.0.0.1:9001/api/v1/stock-app/webhook -Method Post -Headers @{ Accept = 'application/json' } -ContentType 'application/json' -Body $body
```

Returned order:

```powershell
$body = @{
    event_type = 'order_returned'
    external_id = 'ORDER-101'
    sku_code = 'BAG-PREMIUM'
    quantity = 1
    channel = 'COD'
} | ConvertTo-Json

Invoke-RestMethod -Uri http://127.0.0.1:9001/api/v1/stock-app/webhook -Method Post -Headers @{ Accept = 'application/json' } -ContentType 'application/json' -Body $body
```

Resend flow:

```powershell
$body = @{
    event_type = 'order_resent'
    external_id = 'ORDER-102'
    sku_code = 'BAG-CLASSIC'
    quantity = 1
    channel = 'COD'
} | ConvertTo-Json

Invoke-RestMethod -Uri http://127.0.0.1:9001/api/v1/stock-app/webhook -Method Post -Headers @{ Accept = 'application/json' } -ContentType 'application/json' -Body $body
```

Health summary:

```powershell
Invoke-RestMethod -Uri 'http://127.0.0.1:9001/api/v1/health/summary?business_name=Horns%20England%20Pvt%20Ltd' -Headers @{ Accept = 'application/json' }
```

## Core Business Rules

- Delivered: revenue is recognized, production cost, delivery cost, and verification cost apply.
- Returned: return leakage and courier pressure are recorded.
- Resent: no new COGS is applied; retry operational cost is recorded.
- Fake order: maximum operational leakage is recorded.
- Cancel before dispatch: can be recorded as an operational event with minimal direct cost.

## Tests

```bash
php artisan test
```

Current tests cover:

- app redirect behavior
- delivered order impact
- resend impact without new COGS
