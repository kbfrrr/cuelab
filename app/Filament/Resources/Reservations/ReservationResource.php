<?php

namespace App\Filament\Resources\Reservations;

use App\Filament\Resources\Reservations\Pages\ManageReservations;
use App\Models\Reservation;
use App\Services\ReservationService;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ReservationResource extends Resource
{
    protected static ?string $model = Reservation::class;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_staff;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
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
        return $table->columns([TextColumn::make('reference')->searchable()->sortable(),
            TextColumn::make('billiardTable.name')->searchable()->sortable(),
            TextColumn::make('customer_name')->searchable()->sortable(),
            TextColumn::make('phone')->searchable()->sortable(),
            TextColumn::make('starts_at')->searchable()->sortable(),
            TextColumn::make('ends_at')->searchable()->sortable(),
            TextColumn::make('quoted_total_minor')->label('Quoted total')->money('PHP', divideBy: 100)->sortable(),
            TextColumn::make('status')->searchable()->sortable(),
            TextColumn::make('hold_expires_at')->searchable()->sortable()])->filters([SelectFilter::make('status')->options(['pending' => 'Pending approval', 'confirmed' => 'Confirmed', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'expired' => 'Expired'])])->recordActions([Action::make('confirmed')->label('Approve')->requiresConfirmation()->visible(fn ($record) => in_array($record->status, ['pending']))->action(fn ($record) => app(ReservationService::class)->transition($record, 'confirmed', auth()->user())),
                Action::make('rejected')->label('Reject')->requiresConfirmation()->visible(fn ($record) => in_array($record->status, ['pending']))->action(fn ($record) => app(ReservationService::class)->transition($record, 'rejected', auth()->user())),
                Action::make('cancelled')->label('Cancel')->requiresConfirmation()->visible(fn ($record) => in_array($record->status, ['pending', 'confirmed']))->action(fn ($record) => app(ReservationService::class)->transition($record, 'cancelled', auth()->user()))])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageReservations::route('/')];
    }
}
