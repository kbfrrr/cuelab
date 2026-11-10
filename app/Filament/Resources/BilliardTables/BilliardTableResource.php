<?php

namespace App\Filament\Resources\BilliardTables;

use App\Filament\Resources\BilliardTables\Pages\ManageBilliardTables;
use App\Models\BilliardTable;
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

class BilliardTableResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Venue';

    protected static ?int $navigationSort = 4;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $model = BilliardTable::class;

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
        return $schema->components([TextInput::make('name')->required()->maxLength(100)->unique(ignoreRecord: true), TextInput::make('type')->required()->maxLength(100), Select::make('service_rate_id')->relationship('serviceRate', 'name')->required(), Textarea::make('description')->maxLength(2000), Toggle::make('is_active')->default(true)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('type')->searchable()->sortable(),
            TextColumn::make('serviceRate.name')->searchable()->sortable(),
            IconColumn::make('is_active')->boolean()->sortable()])->filters([])->recordActions([EditAction::make()])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageBilliardTables::route('/')];
    }
}
