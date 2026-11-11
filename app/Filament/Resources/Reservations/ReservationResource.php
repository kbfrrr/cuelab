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
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

        return $table
            ->poll('15s')
            ->stackedOnMobile()
            ->searchPlaceholder('Guest, table, or reference')
            ->columns([
                TextColumn::make('customer_name')->label('Guest')
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Reservation $record): string => $record->phone)
                    ->searchable(['customer_name', 'phone', 'reference', 'starts_at', 'ends_at', 'hold_expires_at'])
                    ->sortable()->wrap(),
                TextColumn::make('billiardTable.name')->label('Table & schedule')->wrapHeader()
                    ->weight(FontWeight::Medium)
                    ->description(fn (Reservation $record): string => static::scheduleLabel($record))
                    ->searchable()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('starts_at', $direction))
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $statuses[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'confirmed' => 'success',
                        'pending' => 'warning',
                        'rejected', 'no_show' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Reservation $record): ?string => static::statusDescription($record))
                    ->searchable()->sortable(),
                TextColumn::make('quoted_total_minor')->label('Quoted total')->wrapHeader()
                    ->money('PHP', divideBy: 100)->alignEnd()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options($statuses)])
            ->recordActions([
                ActionGroup::make([static::checkInAction(), static::lateCallAction(), static::noShowAction()])
                    ->label('Arrival')->icon('heroicon-o-clock')->button()->outlined()->size('sm'),
                ActionGroup::make([
                    static::detailsAction(),
                    Action::make('confirmed')->label('Approve')->icon('heroicon-o-check')->requiresConfirmation()
                        ->visible(fn (Reservation $record): bool => $record->status === 'pending')
                        ->action(fn (Reservation $record) => app(ReservationService::class)->transition($record, 'confirmed', auth()->user())),
                    Action::make('rejected')->label('Reject')->icon('heroicon-o-x-mark')->color('danger')->requiresConfirmation()
                        ->visible(fn (Reservation $record): bool => $record->status === 'pending')
                        ->action(fn (Reservation $record) => app(ReservationService::class)->transition($record, 'rejected', auth()->user())),
                    Action::make('cancelled')->label('Cancel reservation')->icon('heroicon-o-x-circle')->color('danger')->requiresConfirmation()
                        ->visible(fn (Reservation $record): bool => in_array($record->status, ['pending', 'confirmed'], true))
                        ->action(fn (Reservation $record) => app(ReservationService::class)->transition($record, 'cancelled', auth()->user())),
                ])->label('More actions')->icon('heroicon-o-ellipsis-horizontal')->iconButton()->tooltip('More actions'),
            ])->recordActionsColumnLabel('Actions')
            ->defaultSort('id', 'desc');
    }

    private static function scheduleLabel(Reservation $record): string
    {
        $start = $record->starts_at->format('M j, Y · g:i A');
        $end = $record->ends_at->format($record->starts_at->isSameDay($record->ends_at) ? 'g:i A' : 'M j, Y · g:i A');

        return $start.' – '.$end;
    }

    private static function statusDescription(Reservation $record): ?string
    {
        if ($record->status === 'pending' && $record->hold_expires_at !== null) {
            return 'Held until '.$record->hold_expires_at->format('M j, g:i A');
        }

        $details = array_filter([static::sessionLabel($record), static::deadlineLabel($record)]);

        return $details === [] ? null : implode(' · ', $details);
    }

    private static function detailsAction(): Action
    {
        return Action::make('details')->label('View details')->icon('heroicon-o-document-text')
            ->modalHeading('Reservation details')->slideOver()->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->schema([
                TextEntry::make('customer_name')->label('Guest'),
                TextEntry::make('phone'),
                TextEntry::make('billiardTable.name')->label('Billiard table'),
                TextEntry::make('quoted_total_minor')->label('Quoted total')->money('PHP', divideBy: 100),
                TextEntry::make('starts_at')->label('Scheduled start')->dateTime('M j, Y · g:i A'),
                TextEntry::make('ends_at')->label('Scheduled end')->dateTime('M j, Y · g:i A'),
                TextEntry::make('hold_expires_at')->label('Hold expires at')->dateTime('M j, Y · g:i A')
                    ->visible(fn (Reservation $record): bool => $record->hold_expires_at !== null),
                TextEntry::make('reference')->label('Booking reference')->copyable()->columnSpanFull(),
            ]);
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
