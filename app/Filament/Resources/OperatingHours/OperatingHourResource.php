<?php

namespace App\Filament\Resources\OperatingHours;

use App\Filament\Resources\OperatingHours\Pages\ManageOperatingHours;
use App\Models\OperatingHour;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class OperatingHourResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Venue';

    protected static ?int $navigationSort = 7;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $model = OperatingHour::class;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_staff;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $dismissTimePicker = 'if (isOpen()) { $event.stopPropagation(); closePanel(true) }';

        return $schema->components([
            Select::make('day_of_week')->native(false)->options([0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'])->required()->unique(ignoreRecord: true),
            Toggle::make('is_closed')->live(),
            TimePicker::make('opens_at')->native(false)->displayFormat('g:i A')->suffixIcon('heroicon-o-clock', isInline: true)
                ->extraAlpineAttributes(['x-on:keydown.escape' => $dismissTimePicker])
                ->seconds(false)->required(fn ($get) => ! $get('is_closed')),
            TimePicker::make('closes_at')->native(false)->displayFormat('g:i A')->suffixIcon('heroicon-o-clock', isInline: true)
                ->extraAlpineAttributes(['x-on:keydown.escape' => $dismissTimePicker])
                ->seconds(false)->required(fn ($get) => ! $get('is_closed'))->helperText('A closing time before opening means the following day.'),
            Toggle::make('is_verified')->label('Verified by venue'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('day_of_week')->label('Day')->formatStateUsing(fn ($state) => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$state])->sortable(),
            TextColumn::make('opens_at')->searchable()->sortable(),
            TextColumn::make('closes_at')->searchable()->sortable(),
            TextColumn::make('is_closed')->label('Hours status')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Closed' : 'Open')
                ->color(fn (bool $state): string => $state ? 'gray' : 'success')->sortable(),
            TextColumn::make('is_verified')->label('Verification')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Verified' : 'Unverified')
                ->color(fn (bool $state): string => $state ? 'success' : 'warning')->sortable()])->filters([])
            ->recordActions([EditAction::make()])
            ->recordActionsColumnLabel('Actions')
            ->defaultSort('day_of_week');
    }

    public static function getPages(): array
    {
        return ['index' => ManageOperatingHours::route('/')];
    }
}
