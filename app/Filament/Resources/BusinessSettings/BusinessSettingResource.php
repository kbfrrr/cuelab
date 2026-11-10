<?php

namespace App\Filament\Resources\BusinessSettings;

use App\Filament\Resources\BusinessSettings\Pages\ManageBusinessSettings;
use App\Models\BusinessSetting;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BusinessSettingResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 8;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $model = BusinessSetting::class;

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
        return $schema->components([TextInput::make('key')->disabled()->dehydrated(false), TextInput::make('value')->label('Value')->helperText('booking_enabled: true/false. hold_minutes: 1–1440. Other settings: text.')->formatStateUsing(fn ($state) => is_bool($state) ? ($state ? 'true' : 'false') : $state)->rules(fn ($record) => match ($record?->key) {
            'booking_enabled' => ['required', 'in:true,false'], 'hold_minutes' => ['required', 'integer', 'min:1', 'max:1440'], default => ['nullable', 'string', 'max:2000']
        })->dehydrateStateUsing(fn ($state, $record) => match ($record?->key) {
            'booking_enabled' => $state === 'true', 'hold_minutes' => (int) $state, default => $state
        }), Toggle::make('is_verified')->label('Verified by venue')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('key')->searchable()->sortable(),
            TextColumn::make('value')->searchable()->sortable(),
            IconColumn::make('is_verified')->boolean()->sortable()])->filters([])->recordActions([EditAction::make()])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageBusinessSettings::route('/')];
    }
}
