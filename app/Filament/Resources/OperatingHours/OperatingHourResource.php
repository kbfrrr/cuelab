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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class OperatingHourResource extends Resource
{
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
        return $schema->components([Select::make('day_of_week')->options([0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'])->required()->unique(ignoreRecord: true), Toggle::make('is_closed')->live(), TimePicker::make('opens_at')->seconds(false)->required(fn ($get) => ! $get('is_closed')), TimePicker::make('closes_at')->seconds(false)->required(fn ($get) => ! $get('is_closed'))->helperText('A closing time before opening means the following day.'), Toggle::make('is_verified')->label('Verified by venue')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('day_of_week')->label('Day')->formatStateUsing(fn ($state) => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$state])->sortable(),
            TextColumn::make('opens_at')->searchable()->sortable(),
            TextColumn::make('closes_at')->searchable()->sortable(),
            IconColumn::make('is_closed')->boolean()->sortable(),
            IconColumn::make('is_verified')->boolean()->sortable()])->filters([])->recordActions([EditAction::make()])->defaultSort('day_of_week');
    }

    public static function getPages(): array
    {
        return ['index' => ManageOperatingHours::route('/')];
    }
}
