<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\ServiceBillingRecord;
use App\Filament\Concerns\RespectsBusinessModules;
use App\Filament\Resources\ServiceBillingResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ServiceBillingResource extends Resource
{
    use RespectsBusinessModules;

    protected static ?string $model = ServiceBillingRecord::class;
    protected static ?string $navigationGroup = 'Sales & Work';
    protected static ?string $navigationLabel = 'Service Billing';
    protected static ?string $navigationIcon = 'heroicon-o-credit-card';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Service client')
                ->description('Use this for service businesses that collect registration fees and monthly subscription money.')
                ->schema([
                    Select::make('business_id')
                        ->label('Business')
                        ->options(fn () => static::serviceBusinessOptions())
                        ->default(fn () => static::defaultServiceBusinessId())
                        ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                        ->dehydrated()
                        ->required(),
                    TextInput::make('client_name')
                        ->label('Client name')
                        ->placeholder('Client or company paying the service fee')
                        ->required()
                        ->maxLength(255),
                    Select::make('billing_type')
                        ->label('What money is this?')
                        ->options(ServiceBillingRecord::billingTypeOptions())
                        ->default(ServiceBillingRecord::TYPE_SUBSCRIPTION)
                        ->required(),
                    TextInput::make('amount_due')
                        ->label('Amount to collect')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0)
                        ->required()
                        ->live(),
                ])
                ->columns(2),
            Section::make('Month and payment')
                ->schema([
                    DatePicker::make('period_start')
                        ->label('Month starts'),
                    DatePicker::make('period_end')
                        ->label('Month ends'),
                    DatePicker::make('due_on')
                        ->label('Due date'),
                    Select::make('payment_status')
                        ->label('Payment status')
                        ->options(ServiceBillingRecord::paymentStatusOptions())
                        ->default('unpaid')
                        ->required(),
                    TextInput::make('paid_amount')
                        ->label('Paid amount')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0)
                        ->live()
                        ->required(),
                    Placeholder::make('balance_due')
                        ->label('Still to collect')
                        ->content(function ($get): string {
                            $amount = (float) ($get('amount_due') ?? 0);
                            $paid = (float) ($get('paid_amount') ?? 0);
                            $balance = max($amount - $paid, 0);

                            return 'LKR '.number_format($balance, 2);
                        }),
                    DatePicker::make('paid_on')
                        ->label('Paid date'),
                    Select::make('payment_method')
                        ->label('Payment method')
                        ->options([
                            'cash' => 'Cash',
                            'bank' => 'Bank transfer',
                            'cheque' => 'Cheque',
                            'card' => 'Card',
                            'other' => 'Other',
                        ])
                        ->placeholder('Not selected'),
                    TextInput::make('reference')
                        ->label('Reference')
                        ->placeholder('Receipt, cheque, bank ref'),
                    Textarea::make('note')
                        ->label('Note')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::scopeToServiceBusinesses($query))
            ->defaultSort('due_on')
            ->columns([
                Tables\Columns\TextColumn::make('business.name')->label('Business')->toggleable(),
                Tables\Columns\TextColumn::make('client_name')->label('Client')->searchable(),
                Tables\Columns\TextColumn::make('billing_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ServiceBillingRecord::billingTypeOptions()[$state] ?? 'Other'),
                Tables\Columns\TextColumn::make('period_start')->label('From')->date()->toggleable(),
                Tables\Columns\TextColumn::make('period_end')->label('To')->date()->toggleable(),
                Tables\Columns\TextColumn::make('amount_due')->label('Due')->money('LKR')->sortable(),
                Tables\Columns\TextColumn::make('paid_amount')->label('Paid')->money('LKR')->sortable(),
                Tables\Columns\TextColumn::make('balance_due')
                    ->label('Still to collect')
                    ->state(fn (ServiceBillingRecord $record): float => $record->balanceDue())
                    ->money('LKR'),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'partial' => 'warning',
                        'overdue' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('due_on')->label('Due date')->date()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label('Payment status')
                    ->options(ServiceBillingRecord::paymentStatusOptions()),
                Tables\Filters\SelectFilter::make('billing_type')
                    ->label('Billing type')
                    ->options(ServiceBillingRecord::billingTypeOptions()),
            ])
            ->actions([
                Action::make('markPaid')
                    ->label('Mark paid')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ServiceBillingRecord $record): bool => $record->balanceDue() > 0 || $record->payment_status !== 'paid')
                    ->action(function (ServiceBillingRecord $record): void {
                        $record->update([
                            'paid_amount' => $record->amount_due,
                            'payment_status' => 'paid',
                            'paid_on' => now()->toDateString(),
                        ]);
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiceBillingRecords::route('/'),
            'create' => Pages\CreateServiceBillingRecord::route('/create'),
            'edit' => Pages\EditServiceBillingRecord::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && static::hasAccessibleServiceBusiness();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check() && (($user?->isOwner() ?? false)
            || ($user?->isInternalAdmin() ?? false)
            || ($user?->canAccessFinanceOperations() ?? false))
            && static::hasAccessibleServiceBusiness();
    }

    private static function serviceBusinessOptions(): array
    {
        return static::businessOptionsMatching(fn (Business $business): bool => $business->supportsBusinessType(Business::TYPE_SERVICE));
    }

    private static function defaultServiceBusinessId(): ?int
    {
        $default = Auth::user()?->defaultBusinessId();
        $business = $default ? Business::query()->find($default) : null;

        if ($business?->supportsBusinessType(Business::TYPE_SERVICE)) {
            return $business->id;
        }

        return array_key_first(static::serviceBusinessOptions());
    }

    private static function hasAccessibleServiceBusiness(): bool
    {
        return static::hasAccessibleBusinessMatching(fn (Business $business): bool => $business->supportsBusinessType(Business::TYPE_SERVICE));
    }

    private static function scopeToServiceBusinesses(Builder $query): Builder
    {
        return static::scopeToAccessibleBusinessesMatching($query, fn (Business $business): bool => $business->supportsBusinessType(Business::TYPE_SERVICE));
    }
}
