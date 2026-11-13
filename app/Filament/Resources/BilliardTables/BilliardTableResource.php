<?php

namespace App\Filament\Resources\BilliardTables;

use App\Filament\Resources\BilliardTables\Pages\ManageBilliardTables;
use App\Models\BilliardTable;
use App\Models\ServiceRate;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
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
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('service_rate_id')->label('Hourly rate')->native(false)->relationship('serviceRate', 'name')->required()
                ->getOptionLabelFromRecordUsing(fn (ServiceRate $record): string => $record->name.' · ₱'.number_format($record->amount_minor / 100, 2).'/hour')
                ->helperText('Used to quote bookings. Manage the amount in Service Rates.'),
            Textarea::make('description')->maxLength(2000)->columnSpanFull(),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('serviceRate.amount_minor')->label('Hourly rate')->money('PHP', divideBy: 100)->sortable(),
            TextColumn::make('is_active')->label('Status')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                ->color(fn (bool $state): string => $state ? 'success' : 'gray')->sortable()])->filters([])
            ->recordActions([EditAction::make()])
            ->recordActionsColumnLabel('Actions')
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageBilliardTables::route('/')];
    }
}
