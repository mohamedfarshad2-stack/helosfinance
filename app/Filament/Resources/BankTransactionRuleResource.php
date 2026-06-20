<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\BankTransactionRule;
use App\Domains\Shared\Models\Business;
use App\Filament\Resources\BankTransactionRuleResource\Pages;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class BankTransactionRuleResource extends Resource
{
    protected static ?string $model = BankTransactionRule::class;
    protected static ?string $navigationGroup = 'Setup';
    protected static ?string $navigationLabel = 'Bank Rules';
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('business_id')
                ->options(fn () => static::businessOptions())
                ->required(),
            TextInput::make('match_text')->required(),
            Select::make('classification')->options([
                'revenue' => 'Revenue',
                'expense' => 'Expense',
                'salary' => 'Salary',
                'supplier_payment' => 'Supplier payment',
                'courier' => 'Courier',
                'fuel' => 'Fuel',
                'packing' => 'Packing',
                'marketing' => 'Marketing',
                'rent' => 'Rent',
                'utility' => 'Utility',
                'bank_charge' => 'Bank charge',
                'owner_withdrawal' => 'Owner withdrawal',
                'transfer' => 'Transfer',
                'petty_cash' => 'Petty cash',
                'maintenance' => 'Maintenance',
            ])->required(),
            TextInput::make('confidence')->numeric()->default(0.75),
            Select::make('active')->options([1 => 'Active', 0 => 'Inactive'])->default(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => static::scopeToCurrentBusiness($query))->columns([
            Tables\Columns\TextColumn::make('business.name')->label('Client')->searchable(),
            Tables\Columns\TextColumn::make('match_text')->searchable(),
            Tables\Columns\TextColumn::make('classification')->badge(),
            Tables\Columns\TextColumn::make('confidence')->formatStateUsing(fn ($state) => number_format((float) $state, 2)),
            Tables\Columns\IconColumn::make('active')->boolean(),
            Tables\Columns\TextColumn::make('last_matched_at')->dateTime()->toggleable(),
        ])->actions([
            Tables\Actions\EditAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBankTransactionRules::route('/'),
            'create' => Pages\CreateBankTransactionRule::route('/create'),
            'edit' => Pages\EditBankTransactionRule::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false));
    }

    private static function businessOptions(): array
    {
        $user = Auth::user();

        return Business::query()
            ->when(! $user?->seesAllBusinesses(), fn (Builder $query) => $query->whereIn('id', $user?->accessibleBusinessIds() ?? []))
            ->pluck('name', 'id')
            ->all();
    }

    private static function scopeToCurrentBusiness(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->seesAllBusinesses()) {
            return $query;
        }

        return $query->whereIn('business_id', $user?->accessibleBusinessIds() ?? []);
    }
}
