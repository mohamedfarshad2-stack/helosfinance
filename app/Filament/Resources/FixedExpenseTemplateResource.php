<?php

namespace App\Filament\Resources;

use App\Domains\Shared\Models\FixedExpenseTemplate;
use App\Filament\Resources\FixedExpenseTemplateResource\Pages;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class FixedExpenseTemplateResource extends Resource
{
    protected static ?string $model = FixedExpenseTemplate::class;
    protected static ?string $navigationGroup = 'Platform Setup';
    protected static ?string $navigationLabel = 'Expense Templates';
    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('key')->required(),
            TextInput::make('label')->required(),
            Select::make('expense_type')
                ->label('Cost behavior')
                ->options([
                    'fixed' => 'Fixed cost',
                    'variable' => 'Variable cost',
                ])
                ->default('fixed')
                ->required(),
            Select::make('department')
                ->label('Department')
                ->placeholder('Search or choose department')
                ->searchable()
                ->options(fn () => static::departmentOptions())
                ->preload(),
            TextInput::make('basis')->placeholder('per order, per delivery, per month, per SKU, etc.'),
            Textarea::make('plain_hint')->label('Plain explanation')->rows(3),
            Toggle::make('common_for_most_businesses')->default(true),
            TextInput::make('sort_order')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            Tables\Columns\TextColumn::make('label')->searchable(),
            Tables\Columns\TextColumn::make('expense_type')->label('Cost behavior')->badge(),
            Tables\Columns\TextColumn::make('department')->badge(),
            Tables\Columns\TextColumn::make('basis')->badge(),
            Tables\Columns\TextColumn::make('plain_hint')->limit(70),
            Tables\Columns\IconColumn::make('common_for_most_businesses')->boolean(),
        ])->actions([Tables\Actions\EditAction::make()]);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false));
    }

    public static function canAccess(): bool
    {
        return Auth::check() && ((Auth::user()?->isOwner() ?? false) || (Auth::user()?->isInternalAdmin() ?? false));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFixedExpenseTemplates::route('/'),
            'create' => Pages\CreateFixedExpenseTemplate::route('/create'),
            'edit' => Pages\EditFixedExpenseTemplate::route('/{record}/edit'),
        ];
    }

    private static function departmentOptions(): array
    {
        return collect([
            'Operations',
            'Dispatch',
            'Packing',
            'Production',
            'Courier',
            'Customer Operations',
            'Finance',
            'Sales',
            'Admin',
            'HR',
            'Other',
        ])->mapWithKeys(fn (string $department): array => [$department => $department])->all();
    }
}
