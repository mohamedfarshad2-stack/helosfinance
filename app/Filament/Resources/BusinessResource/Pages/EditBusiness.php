<?php

namespace App\Filament\Resources\BusinessResource\Pages;

use App\Domains\FinancialClarity\Services\BusinessHealthSnapshotService;
use App\Domains\Shared\Models\Business;
use App\Filament\Resources\BusinessResource;
use App\Filament\Resources\ServiceBillingResource;
use App\Filament\Resources\SkuResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditBusiness extends EditRecord
{
    protected static string $resource = BusinessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('moveToFixedExpenses')
                ->label('Move to fixed expenses')
                ->icon('heroicon-o-arrow-right')
                ->visible(fn (): bool => $this->record->onboarding_status === 'setup')
                ->action(function (): void {
                    $this->record->update(['onboarding_status' => 'fixed_expenses']);
                    $this->refreshFormData(['onboarding_status']);
                    Notification::make()->title('Client moved to fixed expense setup')->success()->send();
                }),
            Actions\Action::make('openSkus')
                ->label('Open SKU costing')
                ->icon('heroicon-o-cube')
                ->url(fn (): string => SkuResource::getUrl('index'))
                ->visible(fn (): bool => $this->record->onboarding_status === 'sku_costing' && $this->record->supportsSkuManagement()),
            Actions\Action::make('moveToSkuCosting')
                ->label('Move to SKU costing')
                ->icon('heroicon-o-arrow-right')
                ->visible(fn (): bool => $this->record->onboarding_status === 'fixed_expenses' && $this->record->supportsSkuManagement())
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['onboarding_status' => 'sku_costing']);
                    $this->refreshFormData(['onboarding_status']);
                    Notification::make()->title('Client moved to SKU costing')->success()->send();
                }),
            Actions\Action::make('moveServiceToReady')
                ->label('Mark ready for service billing')
                ->icon('heroicon-o-arrow-right')
                ->visible(fn (): bool => $this->record->onboarding_status === 'fixed_expenses' && $this->record->supportsBusinessType(Business::TYPE_SERVICE) && ! $this->record->supportsSkuManagement())
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['onboarding_status' => 'ready']);
                    $this->refreshFormData(['onboarding_status']);
                    Notification::make()->title('Service business is ready for billing and clarity')->success()->send();
                }),
            Actions\Action::make('openServiceBilling')
                ->label('Open service billing')
                ->icon('heroicon-o-credit-card')
                ->url(fn (): string => ServiceBillingResource::getUrl('index'))
                ->visible(fn (): bool => $this->record->onboarding_status === 'ready' && $this->record->supportsBusinessType(Business::TYPE_SERVICE) && ! $this->record->supportsSkuManagement()),
            Actions\Action::make('markReady')
                ->label('Mark ready')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->onboarding_status === 'sku_costing' && $this->record->supportsSkuManagement())
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['onboarding_status' => 'ready']);
                    $this->refreshFormData(['onboarding_status']);
                    Notification::make()->title('Client is ready for clarity')->success()->send();
                }),
            Actions\Action::make('refreshSnapshot')
                ->label('Refresh health snapshot')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->record->onboarding_status === 'ready')
                ->action(function (BusinessHealthSnapshotService $snapshots): void {
                    $snapshot = $snapshots->refreshCurrentMonth($this->record);

                    Notification::make()
                        ->title('Health snapshot refreshed')
                        ->body('Saved summary for '.optional($snapshot->period_start)->format('F Y').' updated for this client.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
