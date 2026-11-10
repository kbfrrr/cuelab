<?php

namespace App\Filament\Resources\TournamentRegistrations;

use App\Filament\Resources\TournamentRegistrations\Pages\ManageTournamentRegistrations;
use App\Models\TournamentRegistration;
use App\Services\TournamentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TournamentRegistrationResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $model = TournamentRegistration::class;

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
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('tournament.title'), TextColumn::make('full_name')->searchable(), TextColumn::make('phone'), TextColumn::make('reference')->searchable(), TextColumn::make('quoted_fee_minor')->label('Entry fee')->money('PHP', divideBy: 100), TextColumn::make('status')->badge(), TextColumn::make('payment_method'), TextColumn::make('payment_reference'), TextColumn::make('paid_at')->dateTime()])->recordActions([Action::make('paid')->label('Record payment')->visible(fn ($record) => $record->status === 'unpaid')->schema([Select::make('payment_method')->options(['cash' => 'Cash', 'gcash' => 'GCash'])->required(), TextInput::make('payment_reference')->label('Payment reference (required for GCash)')])->action(fn ($record, array $data) => app(TournamentService::class)->updateRegistration($record, 'paid', auth()->user(), $data)), Action::make('cancelled')->label('Cancel registration')->modalDescription('Cancellation releases the seat. Any refund must be handled separately by staff.')->requiresConfirmation()->visible(fn ($record) => $record->status !== 'cancelled')->action(fn ($record) => app(TournamentService::class)->updateRegistration($record, 'cancelled', auth()->user()))])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageTournamentRegistrations::route('/')];
    }
}
