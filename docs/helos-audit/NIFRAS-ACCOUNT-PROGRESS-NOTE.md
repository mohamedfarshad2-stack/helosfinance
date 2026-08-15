# Nifras Account Progress Note

Date: 2026-08-15  
Scope: Nifras-only account surface inside HELOS  
Status: In progress  
Deployment: Local only at the time of writing

## Goal

Give `nifras@helos.com` a dedicated account page that only this login can open.
The page should show Nifras-specific wholesale and team visibility without exposing the same surface to other staff.

## What is already made

- A dedicated Filament page exists at `app/Filament/Pages/NifrasAccount.php`.
- The page slug is `nifras-account`.
- The page is gated by email, so only `nifras@helos.com` can access it.
- The page uses a separate Blade view at `resources/views/filament/pages/nifras-account.blade.php`.
- A focused access test exists at `tests/Feature/NifrasAccountAccessTest.php`.

## Current page behavior

- Shows a Nifras heading and summary cards when the signed-in user is `nifras@helos.com`.
- Uses current-month wholesale data from `RevenuePipelineService`.
- Shows a direct-report summary based on `Mission` counts.
- Links to operational order events from the page.
- Redirects non-Nifras users away from the page.

## What was intentionally removed

- The earlier generic wholesale page was removed.
- The work is being kept on the Nifras account surface only.
- No Stock App code was changed.

## Files currently involved

- `app/Filament/Pages/NifrasAccount.php`
- `resources/views/filament/pages/nifras-account.blade.php`
- `tests/Feature/NifrasAccountAccessTest.php`

## Verified locally

- PHP syntax checks pass for the page, view, and test.
- The access test passes locally.

## What is not yet done

- The page is not yet confirmed on the hosted environment.
- The page is not yet committed and pushed from this snapshot.
- The wider Nifras CRM/funnel work is still pending if the goal is to build beyond this account page.

## Handoff note

If you continue from here, keep all changes inside the Nifras account page and do not reopen the old wholesale page route.
The next step is to commit and push the Nifras-only account page so the hosted site can pick it up.
