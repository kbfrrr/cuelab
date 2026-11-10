<?php

namespace App\Filament\Resources\ServiceRates;

use App\Filament\Resources\ServiceRates\Pages\ManageServiceRates;
use App\Models\ServiceRate;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ServiceRateResource extends Resource
{
    protected static ?string $model = ServiceRate::class;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_staff;
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
        return $schema->components([TextInput::make('name')->required()->maxLength(150)->unique(ignoreRecord: true), Textarea::make('description')->maxLength(2000), TextInput::make('amount_minor')->label('Hourly rate')->prefix('PHP')->numeric()->step(0.01)->minValue(0)->maxValue(100000)->rules(['decimal:0,2'])->formatStateUsing(fn ($state) => $state === null ? null : number_format($state / 100, 2, '.', ''))->dehydrateStateUsing(fn ($state) => (int) round((float) $state * 100))->required(), Select::make('currency')->options(['PHP' => 'PHP'])->default('PHP')->required(), Select::make('unit')->options(['table/hour' => 'Per table / hour'])->default('table/hour')->required(), Toggle::make('is_active')->default(true), Toggle::make('is_verified')->label('Verified by venue')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('amount_minor')->label('Hourly rate')->money('PHP', divideBy: 100)->sortable(),
            TextColumn::make('currency')->searchable()->sortable(),
            IconColumn::make('is_verified')->boolean()->sortable(),
            IconColumn::make('is_active')->boolean()->sortable()])->filters([])->recordActions([EditAction::make()])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageServiceRates::route('/')];
    }
}
