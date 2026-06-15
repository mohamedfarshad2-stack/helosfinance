<?php

namespace App\Filament\Resources\BusinessResource\RelationManagers;

use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ClientUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Client users';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->state(fn (User $record): string => $record->is_employee ? 'Staff' : 'Owner'),
                Tables\Columns\TextColumn::make('employee_access_profile')
                    ->label('Staff access')
                    ->badge()
                    ->placeholder('-')
                    ->formatStateUsing(fn (?string $state): string => User::employeeAccessProfileOptions()[$state ?? 'operations'] ?? 'Operations'),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('Remove access')
                    ->modalHeading('Remove user access?')
                    ->modalDescription('This removes the login account. Business records and transactions stay in HELOS.')
                    ->visible(fn (User $record): bool => (Auth::user()?->isInternalAdmin() ?? false)
                        && ! $record->isInternalAdmin()
                        && $record->id !== Auth::id()),
            ]);
    }
}
