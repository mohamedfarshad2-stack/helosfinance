# Nifras Account Progress Note

Date: 2026-08-15  
Scope: Nifras-only account surface inside HELOS  
Status: In progress  
Deployment: Pushed to `helosfinance`; hosted verification still pending

## Goal

Give `nifras@helos.com` a dedicated account page that only this login can open.
The page should show Nifras-specific wholesale visibility without exposing the same surface to other staff.

## What is already made

- A dedicated Filament page exists at `app/Filament/Pages/NifrasAccount.php`.
- The page slug is `nifras-account`.
- The page is gated by email, so only `nifras@helos.com` can access it.
- The page uses a separate Blade view at `resources/views/filament/pages/nifras-account.blade.php`.
- A focused access test exists at `tests/Feature/NifrasAccountAccessTest.php`.
- A wholesale order desk now lives inside the Nifras account page itself.
- That desk calculates gross sales, product cost, courier cost, net sales, gross profit, paid amount, and outstanding balance.
- Wholesale customer history and repeat-follow-up timing now appear on the same Nifras account surface.

## Current page behavior

- Shows a Nifras heading and summary cards when the signed-in user is `nifras@helos.com`.
- Shows wholesale pipeline numbers from `RevenuePipelineService`.
- Shows a wholesale order form with product lines, delivery charge, payment, and profit preview.
- Saves wholesale orders into `wholesale_orders`.
- Shows recent wholesale orders and repeat-customer summaries.
- Links to operational order events from the page.
- Redirects non-Nifras users away from the page.
- Direct-report summary has been moved to `TodaysWork` as the team-work surface.
- `TodaysWork` now includes a visible Nifras shortcut so the account page is easier to find.

## What was intentionally removed

- The earlier generic wholesale page was removed.
- The work is being kept on the Nifras account surface only.
- No Stock App code was changed.

## Files currently involved

- `app/Filament/Pages/NifrasAccount.php`
- `resources/views/filament/pages/nifras-account.blade.php`
- `app/Domains/Shared/Models/WholesaleOrder.php`
- `database/migrations/2026_08_15_000001_create_wholesale_orders_table.php`
- `tests/Feature/NifrasAccountAccessTest.php`
- `tests/Feature/NifrasWholesaleOrderCreationTest.php`

## Verified locally

- PHP syntax checks pass for the page, view, and test.
- The access test passes locally.
- The wholesale order creation test passes locally.

## What is not yet done

- The page is not yet confirmed on the hosted environment.
- The page has been committed and pushed to `helosfinance`.
- The wider Nifras CRM/funnel work is still pending if the goal is to build beyond this account page.
- Team review now lives on `TodaysWork` instead of the Nifras account page.
- `TodaysWork` now shows the Nifras shortcut at the top of the work board.

## Handoff note

If you continue from here, keep all changes inside the Nifras account page and do not reopen the old wholesale page route.
The next step is to verify the hosted deployment and confirm the Nifras login can open `/admin/nifras-account`.
