<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\Business;
use App\Domains\Shared\Models\Employee;
use App\Domains\Shared\Models\OperationalEvent;
use App\Domains\Shared\Models\ProductionEntry;
use App\Domains\Shared\Models\Sku;
use App\Domains\Shared\Models\SkuRecipeItem;
use App\Filament\Concerns\RespectsBusinessModules;
use App\Filament\Resources\ProductionEntryResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class ProductionEntryResource extends Resource
{
    use RespectsBusinessModules;

    protected static ?string $model = ProductionEntry::class;
    protected static ?string $navigationGroup = 'Products & Production';
    protected static ?string $navigationLabel = 'Production & Piece Pay';
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static function businessScopeResponsibilities(): array
    {
        return ['production'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Daily quick entry')
                ->description('For weekly piece-pay workers: select the item code, work, worker, and completed quantity. HELOS calculates the pay.')
                ->schema([
                    Select::make('business_id')
                        ->options(fn () => static::businessOptions())
                        ->default(fn () => Auth::user()?->defaultBusinessId())
                        ->disabled(fn (): bool => ! (Auth::user()?->isInternalAdmin() ?? false))
                        ->dehydrated()
                        ->required(),
                    Select::make('sku_id')
                        ->label('Product / SKU')
                        ->placeholder('Select item code')
                        ->live()
                        ->options(fn (Get $get) => static::skuOptions((int) ($get('business_id') ?? 0)))
                        ->searchable()
                        ->preload()
                        ->helperText('Start here. Choose the product code that was worked on.')
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            static::resetLaborSelection($set);
                            static::applySuggestedLaborStep($get, $set);
                        })
                        ->required(),
                    Select::make('production_kind')
                        ->label('What was produced?')
                        ->options([
                            'part_production' => 'Part production',
                            'finished_product' => 'Finished product',
                        ])
                        ->default('part_production')
                        ->live()
                        ->helperText('Most daily entries are Part production. Use Finished product only when the full item was completed.')
                        ->required()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('part_name', null);
                            static::resetLaborSelection($set);
                        }),
                    Select::make('part_name')
                        ->label('Product part')
                        ->placeholder('Select part')
                        ->options(fn (Get $get) => static::partOptions((int) ($get('sku_id') ?? 0)))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->helperText('Example: strap, sole, upper, bottom, packing.')
                        ->visible(fn (Get $get): bool => ($get('production_kind') ?? 'part_production') === 'part_production')
                        ->required(fn (Get $get): bool => ($get('production_kind') ?? 'part_production') === 'part_production')
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            static::resetLaborSelection($set);
                            static::applySuggestedLaborStep($get, $set);
                        }),
                    Select::make('sku_recipe_item_id')
                        ->label('Production work / pay step')
                        ->placeholder('Select work step')
                        ->helperText('This decides the rate per piece.')
                        ->live()
                        ->options(fn (Get $get) => static::laborStepOptions((int) ($get('sku_id') ?? 0), (string) ($get('part_name') ?? ''), (string) ($get('production_kind') ?? 'part_production')))
                        ->searchable()
                        ->preload()
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            $step = static::selectedLaborStep((int) ($get('sku_recipe_item_id') ?? 0));
                            static::applySelectedLaborStep($get, $set, $step);
                        }),
                    Select::make('employee_name')
                        ->label('Worker / employee')
                        ->searchable()
                        ->preload()
                        ->placeholder('Select worker')
                        ->options(fn (Get $get) => static::employeeOptions((int) ($get('business_id') ?? 0)))
                        ->helperText('Choose the person who did this work.')
                        ->required(),
                    TextInput::make('quantity_produced')
                        ->label('Number completed')
                        ->numeric()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            $set('employee_payout', static::calculateGrossPay($get));
                            $set('net_payable', static::calculateNetPayable($get));
                        })
                        ->required()
                        ->minValue(1)
                        ->helperText('Enter only the completed quantity.')
                        ->extraInputAttributes(['inputmode' => 'numeric']),
                    DatePicker::make('produced_on')
                        ->label('Production date')
                        ->required()
                        ->default(now()),
                ])->columns(2),
            Section::make('Calculated pay and adjustments')
                ->description('Open only for waste, advance, deduction, special rate, or marking weekly pay as paid.')
                ->schema([
                    TextInput::make('production_step')
                        ->label('Selected work')
                        ->readOnly()
                        ->dehydrated()
                        ->placeholder('Auto-filled from selected work step'),
                    TextInput::make('piece_rate')
                        ->label('Rate per piece')
                        ->numeric()
                        ->prefix('LKR')
                        ->helperText('Auto-filled from the SKU recipe labor line. Adjust only if this batch has a special rate.')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            $set('employee_payout', static::calculateGrossPay($get));
                            $set('net_payable', static::calculateNetPayable($get));
                        }),
                    TextInput::make('waste_quantity')
                        ->label('Waste / damaged quantity')
                        ->numeric()
                        ->default(0)
                        ->helperText('Optional. Enter only damaged or wasted quantity.'),
                    TextInput::make('employee_payout')
                        ->label('Gross payout')
                        ->helperText('HELOS calculates this from the selected work step and quantity.')
                        ->numeric()
                        ->prefix('LKR')
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => $set('net_payable', static::calculateNetPayable($get))),
                    TextInput::make('advance_amount')
                        ->label('Advance already given')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => $set('net_payable', static::calculateNetPayable($get))),
                    TextInput::make('deduction_amount')
                        ->label('Deduction')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => $set('net_payable', static::calculateNetPayable($get))),
                    TextInput::make('net_payable')
                        ->label('Net payable')
                        ->numeric()
                        ->prefix('LKR')
                        ->default(0)
                        ->readOnly()
                        ->dehydrated(),
                    Select::make('payment_status')
                        ->label('Weekly pay status')
                        ->options([
                            'pending' => 'Pending',
                            'paid' => 'Paid',
                        ])
                        ->default('pending')
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                            if ($state === 'paid' && blank($get('paid_on'))) {
                                $set('paid_on', now()->toDateString());
                            }

                            if ($state !== 'paid') {
                                $set('paid_on', null);
                            }
                        })
                        ->required(),
                    DatePicker::make('paid_on')
                        ->label('Paid on')
                        ->visible(fn (Get $get): bool => ($get('payment_status') ?? 'pending') === 'paid'),
                    TextInput::make('note')
                        ->label('Note')
                        ->placeholder('Optional')
                        ->helperText('Use this only for unusual situations, not for normal daily work.')
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::scopeToCurrentBusiness($query))
            ->defaultSort('produced_on', 'desc')
            ->emptyStateHeading('No production or piece-pay records yet')
            ->emptyStateDescription('Record daily part or finished-product production here. HELOS uses these rows to calculate weekly employee piece-pay.')
            ->columns([
                Tables\Columns\TextColumn::make('produced_on')->date()->sortable(),
                Tables\Columns\TextColumn::make('employee_name')->searchable(),
                Tables\Columns\TextColumn::make('sku.code')->label('SKU')->searchable(),
                Tables\Columns\TextColumn::make('production_kind')->label('Type')->badge()->formatStateUsing(fn (?string $state): string => $state === 'finished_product' ? 'Finished product' : 'Part production'),
                Tables\Columns\TextColumn::make('part_name')->label('Part')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('production_step')->label('Work')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('piece_rate')->label('Rate')->money('LKR')->toggleable(),
                Tables\Columns\TextColumn::make('quantity_produced')->label('Qty')->sortable(),
                Tables\Columns\TextColumn::make('employee_payout')->label('Gross')->money('LKR'),
                Tables\Columns\TextColumn::make('advance_amount')->label('Advance')->money('LKR')->toggleable(),
                Tables\Columns\TextColumn::make('deduction_amount')->label('Deduction')->money('LKR')->toggleable(),
                Tables\Columns\TextColumn::make('net_payable')->label('Net')->money('LKR'),
                Tables\Columns\TextColumn::make('note')->label('Note')->limit(30)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('payment_status')->badge(),
                Tables\Columns\TextColumn::make('paid_on')->date()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('produced_on')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('to')->label('To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('produced_on', '>=', $date))
                            ->when($data['to'] ?? null, fn (Builder $query, $date) => $query->whereDate('produced_on', '<=', $date));
                    }),
                Tables\Filters\SelectFilter::make('payment_status')->options([
                    'pending' => 'Pending',
                    'paid' => 'Paid',
                ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('markPaid')
                    ->label('Mark paid')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ProductionEntry $record): bool => $record->payment_status !== 'paid')
                    ->action(function (ProductionEntry $record): void {
                        $record->update([
                            'payment_status' => 'paid',
                            'paid_on' => now()->toDateString(),
                        ]);

                        OperationalEvent::query()->firstOrCreate(
                            [
                                'business_id' => $record->business_id,
                                'source' => 'production_payout',
                                'event_type' => OperationalEvent::PAYOUT_GENERATED,
                                'external_id' => 'production-entry-'.$record->id,
                            ],
                            [
                                'sku_id' => $record->sku_id,
                                'department' => 'Manufacturing',
                                'quantity' => $record->quantity_produced,
                                'direct_cost_amount' => (float) $record->net_payable,
                                'payload' => ['production_entry_id' => $record->id],
                                'occurred_at' => now(),
                            ]
                        );

                        Notification::make()->title('Production payout marked paid')->success()->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('markPaid')
                        ->label('Mark selected paid')
                        ->icon('heroicon-o-check-circle')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $records->each(function (ProductionEntry $record): void {
                                $record->update([
                                    'payment_status' => 'paid',
                                    'paid_on' => now()->toDateString(),
                                ]);

                                OperationalEvent::query()->firstOrCreate(
                                    [
                                        'business_id' => $record->business_id,
                                        'source' => 'production_payout',
                                        'event_type' => OperationalEvent::PAYOUT_GENERATED,
                                        'external_id' => 'production-entry-'.$record->id,
                                    ],
                                    [
                                        'sku_id' => $record->sku_id,
                                        'department' => 'Manufacturing',
                                        'quantity' => $record->quantity_produced,
                                        'direct_cost_amount' => (float) $record->net_payable,
                                        'payload' => ['production_entry_id' => $record->id],
                                        'occurred_at' => now(),
                                    ]
                                );
                            });

                            Notification::make()->title('Selected production payouts marked paid')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductionEntries::route('/'),
            'create' => Pages\CreateProductionEntry::route('/create'),
            'edit' => Pages\EditProductionEntry::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return Auth::check()
            && (($user?->isOwner() ?? false) || ($user?->isInternalAdmin() ?? false) || ($user?->canAccessProductionWork() ?? false))
            && static::currentBusinessSupportsProductionTracking();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return Auth::check()
            && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false) || ($user?->canAccessProductionWork() ?? false))
            && static::currentBusinessSupportsProductionTracking();
    }

    private static function businessOptions(): array
    {
        $user = Auth::user();

        return static::businessOptionsMatching(fn (Business $business): bool => $business->supportsProductionTracking());
    }

    private static function skuOptions(int $businessId): array
    {
        if ($businessId <= 0) {
            return [];
        }

        return Sku::query()
            ->where('business_id', $businessId)
            ->orderBy('code')
            ->pluck('code', 'id')
            ->all();
    }

    private static function laborStepOptions(int $skuId, string $partName = '', string $productionKind = 'part_production'): array
    {
        if ($skuId <= 0) {
            return [];
        }

        return SkuRecipeItem::query()
            ->where('sku_id', $skuId)
            ->where('active', true)
            ->where('line_type', SkuRecipeItem::TYPE_LABOR)
            ->when($productionKind === 'part_production' && $partName !== '', fn (Builder $query) => $query->where('part_name', $partName))
            ->orderBy('component_name')
            ->get()
            ->mapWithKeys(fn (SkuRecipeItem $item): array => [
                $item->id => ($item->part_name ? $item->part_name.' / ' : '').$item->component_name.' - LKR '.number_format(static::pieceRate($item), 2).' / piece',
            ])
            ->all();
    }

    private static function partOptions(int $skuId): array
    {
        if ($skuId <= 0) {
            return [];
        }

        return SkuRecipeItem::query()
            ->where('sku_id', $skuId)
            ->where('active', true)
            ->whereNotNull('part_name')
            ->where('part_name', '!=', '')
            ->distinct()
            ->orderBy('part_name')
            ->pluck('part_name', 'part_name')
            ->all();
    }

    private static function selectedLaborStep(int $recipeItemId): ?SkuRecipeItem
    {
        if ($recipeItemId <= 0) {
            return null;
        }

        return SkuRecipeItem::query()
            ->whereKey($recipeItemId)
            ->where('active', true)
            ->where('line_type', SkuRecipeItem::TYPE_LABOR)
            ->first();
    }

    private static function pieceRate(SkuRecipeItem $item): float
    {
        return (float) $item->quantity_per_unit * (float) $item->unit_cost;
    }

    private static function resetLaborSelection(Set $set): void
    {
        $set('sku_recipe_item_id', null);
        $set('production_step', null);
        $set('piece_rate', 0);
        $set('employee_payout', 0);
        $set('net_payable', 0);
    }

    private static function applySuggestedLaborStep(Get $get, Set $set): void
    {
        $options = static::laborStepOptions(
            (int) ($get('sku_id') ?? 0),
            (string) ($get('part_name') ?? ''),
            (string) ($get('production_kind') ?? 'part_production')
        );

        if (count($options) !== 1) {
            $set('employee_payout', static::calculateGrossPay($get));
            $set('net_payable', static::calculateNetPayable($get));

            return;
        }

        $recipeItemId = (int) array_key_first($options);
        $set('sku_recipe_item_id', $recipeItemId);

        static::applySelectedLaborStep($get, $set, static::selectedLaborStep($recipeItemId));
    }

    private static function applySelectedLaborStep(Get $get, Set $set, ?SkuRecipeItem $step): void
    {
        $set('part_name', $step?->part_name);
        $set('production_step', $step?->component_name);
        $set('piece_rate', $step ? static::pieceRate($step) : 0);
        $set('employee_payout', static::calculateGrossPay($get));
        $set('net_payable', static::calculateNetPayable($get));
    }

    private static function employeeOptions(int $businessId): array
    {
        if ($businessId <= 0) {
            return [];
        }

        return Employee::query()
            ->where('business_id', $businessId)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    private static function scopeToCurrentBusiness(Builder $query): Builder
    {
        $user = Auth::user();

        return static::scopeToAccessibleBusinessesMatching($query, fn (Business $business): bool => $business->supportsProductionTracking());
    }

    private static function currentBusinessSupportsProductionTracking(): bool
    {
        $user = Auth::user();

        return static::hasAccessibleBusinessMatching(fn (Business $business): bool => $business->supportsProductionTracking());
    }

    private static function calculateNetPayable(Get $get): float
    {
        $gross = (float) ($get('employee_payout') ?? 0);
        $advance = (float) ($get('advance_amount') ?? 0);
        $deduction = (float) ($get('deduction_amount') ?? 0);

        return max($gross - $advance - $deduction, 0);
    }

    private static function calculateGrossPay(Get $get): float
    {
        $skuId = (int) ($get('sku_id') ?? 0);
        $quantity = max((int) ($get('quantity_produced') ?? 0), 0);
        $pieceRate = (float) ($get('piece_rate') ?? 0);

        if ($skuId <= 0 || $quantity <= 0) {
            return 0.0;
        }

        if ($pieceRate > 0) {
            return $pieceRate * $quantity;
        }

        $sku = Sku::query()->find($skuId);

        if (! $sku instanceof Sku) {
            return 0.0;
        }

        return $sku->laborCostPerUnit() * $quantity;
    }
}
