<?php

namespace App\Filament\Resources\AvailabilityBlocks;

use App\Filament\Resources\AvailabilityBlocks\Pages\ManageAvailabilityBlocks;
use App\Models\AvailabilityBlock;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AvailabilityBlockResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Venue';

    protected static ?int $navigationSort = 5;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $model = AvailabilityBlock::class;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_staff;
    }

    public static function canDelete(Model $record): bool
    {
        return ! $record->tournament_id && (bool) auth()->user()?->is_staff;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('billiard_table_id')->native(false)->relationship('billiardTable', 'name')->placeholder('All tables / venue closure'),
            Select::make('type')->native(false)->options(['maintenance' => 'Maintenance', 'closure' => 'Closure', 'tournament' => 'Tournament'])->required(),
            TextInput::make('reason')->required()->maxLength(255),
            DateTimePicker::make('starts_at')->native(false)->displayFormat('M j, Y · g:i A')->suffixIcon('heroicon-o-calendar-days', isInline: true)
                ->seconds(false)->required(),
            DateTimePicker::make('ends_at')->native(false)->displayFormat('M j, Y · g:i A')->suffixIcon('heroicon-o-calendar-days', isInline: true)
                ->seconds(false)->required()->after('starts_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('billiardTable.name')->label('Table')->placeholder('All tables')->searchable()->sortable(),
            TextColumn::make('type')->badge()->formatStateUsing(fn (string $state): string => ucfirst($state))
                ->color(fn (string $state): string => match ($state) {
                    'maintenance' => 'warning',
                    'closure' => 'danger',
                    default => 'gray',
                })->searchable()->sortable(),
            TextColumn::make('reason')->searchable()->sortable(),
            TextColumn::make('starts_at')->searchable()->sortable(),
            TextColumn::make('ends_at')->searchable()->sortable()])->filters([])
            ->recordActions([DeleteAction::make()->requiresConfirmation()])
            ->recordActionsColumnLabel('Actions')
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageAvailabilityBlocks::route('/')];
    }
}
