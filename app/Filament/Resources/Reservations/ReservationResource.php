<?php

namespace App\Filament\Resources\Reservations;

use App\Filament\Resources\Reservations\Pages\ManageReservations;
use App\Models\Reservation;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ReservationResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

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
        $statuses = ['pending' => 'Pending approval', 'confirmed' => 'Confirmed', 'rejected' => 'Rejected',
            'cancelled' => 'Cancelled', 'expired' => 'Expired', 'no_show' => 'No-show'];

        return $table->poll('15s')->columns([
            TextColumn::make('reference')->searchable()->sortable(),
            TextColumn::make('billiardTable.name')->searchable()->sortable(),
            TextColumn::make('customer_name')->searchable()->sortable(),
            TextColumn::make('phone')->searchable()->sortable(),
            TextColumn::make('starts_at')->searchable()->sortable(),
            TextColumn::make('ends_at')->searchable()->sortable(),
            TextColumn::make('quoted_total_minor')->label('Quoted total')->money('PHP', divideBy: 100)->sortable(),
            TextColumn::make('status')->formatStateUsing(fn (string $state): string => $statuses[$state] ?? $state)->searchable()->sortable(),
            TextColumn::make('hold_expires_at')->searchable()->sortable(),
            TextColumn::make('session_ends_at')->label('Session')
                ->state(fn (Reservation $record): ?string => static::sessionLabel($record))
                ->description(fn (Reservation $record): ?string => static::deadlineLabel($record)),
        ])->filters([SelectFilter::make('status')->options($statuses)])->recordActions([
            Action::make('confirmed')->label('Approve')->requiresConfirmation()->visible(fn ($record) => $record->status === 'pending')->action(fn ($record) => app(ReservationService::class)->transition($record, 'confirmed', auth()->user())),
            Action::make('rejected')->label('Reject')->requiresConfirmation()->visible(fn ($record) => $record->status === 'pending')->action(fn ($record) => app(ReservationService::class)->transition($record, 'rejected', auth()->user())),
            Action::make('cancelled')->label('Cancel')->requiresConfirmation()->visible(fn ($record) => in_array($record->status, ['pending', 'confirmed']))->action(fn ($record) => app(ReservationService::class)->transition($record, 'cancelled', auth()->user())),
            ActionGroup::make([static::checkInAction(), static::lateCallAction(), static::noShowAction()])
                ->label('Arrival')->icon('heroicon-o-clock')->link(),
        ])->defaultSort('id', 'desc');
    }

    private static function sessionLabel(Reservation $record): ?string
    {
        if ($record->status !== 'confirmed') {
            return null;
        }

        if ($record->session_ends_at !== null) {
            $prefix = $record->checked_in_at === null ? 'On the way · ' : 'Checked in · ';

            return $prefix.'Ends '.$record->session_ends_at->format('M j, g:i A');
        }

        if ($record->source === 'website' && $record->starts_at->isFuture()) {
            return 'Check-in opens '.$record->starts_at->format('M j, g:i A');
        }

        return $record->late_contact_outcome === 'no_answer' ? 'No answer' : null;
    }

    private static function deadlineLabel(Reservation $record): ?string
    {
        $deadline = $record->replyDeadline();
        if ($deadline === null || $record->effectiveEnd()->lte(now())) {
            return null;
        }

        $label = $record->late_contact_outcome === 'coming' ? 'Arrive by ' : 'Wait until ';

        return $label.$deadline->format('g:i A');
    }

    private static function checkInAction(): Action
    {
        return Action::make('check_in')->label('Check in')->icon('heroicon-o-check-circle')
            ->disabled(fn (Reservation $record): bool => ! $record->canCheckIn(CarbonImmutable::now()))
            ->tooltip(fn (Action $action): ?string => $action->isDisabled()
                ? 'Available during a confirmed website reservation, before the guest checks in.'
                : null)
            ->modalDescription(fn (Reservation $record): string => $record->session_starts_at
                ? 'Check in the guest. Their current timer keeps running.'
                : 'Check in the guest and start their session.')
            ->modalSubmitActionLabel('Check in')
            ->fillForm(fn (Reservation $record): array => static::offerFormData($record))
            ->schema(static::offerFields(fn (Get $get): bool => $get('offer_key') !== 'running'))
            ->action(fn (Action $action, Reservation $record, array $data) => static::runArrivalAction(
                $action, fn () => app(ReservationService::class)->checkIn($record, auth()->user(), static::acceptedOffer($data)), 'Guest checked in',
            ));
    }

    private static function lateCallAction(): Action
    {
        return Action::make('late_call')->label('Record call outcome')->icon('heroicon-o-phone')
            ->disabled(fn (Reservation $record): bool => ! $record->canRecordLateCall(CarbonImmutable::now()))
            ->tooltip(fn (Action $action): ?string => $action->isDisabled()
                ? 'Available after the 15-minute grace period while the guest is still absent and the reservation is active.'
                : null)
            ->modalDescription(fn (Reservation $record): string => 'Call the booked guest at '.$record->phone.'.')
            ->modalSubmitActionLabel('Save outcome')
            ->fillForm(fn (Reservation $record): array => static::offerFormData($record))
            ->schema([
                static::walkInCheckbox(),
                Select::make('outcome')->label('Call outcome')->options(function (Reservation $record): array {
                    return match ($record->late_contact_outcome) {
                        'coming' => ['declined' => 'Guest is no longer coming'],
                        'no_answer' => ['coming' => 'Guest is coming', 'declined' => 'Guest declined'],
                        default => ['coming' => 'Guest is coming', 'declined' => 'Guest declined', 'no_answer' => 'No answer'],
                    };
                })->live()->required(),
                ...static::offerFields(fn (Get $get): bool => $get('outcome') === 'coming'),
            ])->action(fn (Action $action, Reservation $record, array $data) => static::runArrivalAction(
                $action,
                fn () => app(ReservationService::class)->recordLateCall($record, $data['outcome'], (bool) $data['walk_in_waiting'], auth()->user(), static::acceptedOffer($data)),
                match ($data['outcome']) {
                    'declined' => 'Table released',
                    'coming' => 'Session timer started',
                    default => 'Call recorded. Wait five minutes before releasing the table.',
                },
            ));
    }

    private static function noShowAction(): Action
    {
        return Action::make('release_no_show')->label('Release as no-show')->icon('heroicon-o-x-circle')->color('danger')
            ->disabled(fn (Reservation $record): bool => ! $record->canReleaseNoShow(CarbonImmutable::now()))
            ->tooltip(fn (Action $action): ?string => $action->isDisabled()
                ? 'Available when the five-minute wait has ended and the guest still hasn’t checked in.'
                : null)
            ->modalDescription('This releases the table. Use Record walk-in to assign an available slot.')
            ->modalSubmitActionLabel('Release table')
            ->schema([static::walkInCheckbox()])
            ->action(fn (Action $action, Reservation $record, array $data) => static::runArrivalAction(
                $action,
                fn () => app(ReservationService::class)->releaseNoShow($record, (bool) $data['walk_in_waiting'], auth()->user()),
                'Table released',
            ));
    }

    private static function walkInCheckbox(): Checkbox
    {
        return Checkbox::make('walk_in_waiting')->label('A walk-in is waiting and this is the only suitable table.')->accepted();
    }

    private static function offerFormData(Reservation $record): array
    {
        if ($record->session_starts_at !== null) {
            return ['offer_key' => 'running'];
        }

        $offer = app(ReservationService::class)->sessionOffer($record, CarbonImmutable::now());
        if ($offer === null) {
            return ['offer_summary' => 'Fewer than 15 minutes are available. Offer another table or time.'];
        }

        $minutes = intdiv($offer['seconds'] + 59, 60);
        $summary = ($offer['shortened'] ? 'Up to ' : '').$minutes.' minutes · PHP '.number_format($offer['total_minor'] / 100, 2);
        if ($offer['shortened']) {
            $summary .= ' · Ends '.$offer['ends_at']->format('M j, g:i A');
        }

        return ['offer_key' => $offer['key'], 'offer_summary' => $summary];
    }

    private static function offerFields(\Closure $visible): array
    {
        return [
            Hidden::make('offer_key'),
            Hidden::make('offer_summary'),
            TextEntry::make('session_offer')->label('Session offer')->state(fn (Get $get): ?string => $get('offer_summary'))->visible($visible),
            Checkbox::make('offer_accepted')->label('The guest agrees to the shorter session and revised quote.')
                ->visible(fn (Get $get): bool => $visible($get) && str_contains($get('offer_key') ?? '', ':'))
                ->accepted(),
        ];
    }

    private static function acceptedOffer(array $data): ?string
    {
        return ($data['offer_accepted'] ?? false) ? ($data['offer_key'] ?? null) : null;
    }

    private static function runArrivalAction(Action $action, \Closure $operation, string $message): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
            $action->halt();
        }

        Notification::make()->success()->title($message)->send();
    }

    public static function getPages(): array
    {
        return ['index' => ManageReservations::route('/')];
    }
}
