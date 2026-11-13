<?php

namespace App\Filament\Resources\TournamentRegistrations;

use App\Filament\Resources\TournamentRegistrations\Pages\ManageTournamentRegistrations;
use App\Models\TournamentRegistration;
use App\Services\TournamentPaymentProofService;
use App\Services\TournamentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->addSelect([
                'proof_status' => DB::table('tournament_payment_proofs')->select('status')
                    ->whereColumn('tournament_registration_id', 'tournament_registrations.id'),
            ])->with('tournament'))
            ->columns([
                TextColumn::make('tournament.title'),
                TextColumn::make('full_name')->searchable(),
                TextColumn::make('phone'),
                TextColumn::make('reference')->searchable(),
                TextColumn::make('quoted_fee_minor')->label('Entry fee')->money('PHP', divideBy: 100),
                TextColumn::make('status')->badge(),
                TextColumn::make('proof_status')->label('Payment proof')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'pending' => 'Under review',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                }),
                TextColumn::make('payment_method'),
                TextColumn::make('payment_reference'),
                TextColumn::make('paid_at')->dateTime(),
            ])
            ->filters([
                Filter::make('pending_proof')->label('Awaiting payment review')->query(fn (Builder $query): Builder => $query
                    ->where('tournament_registrations.status', 'unpaid')
                    ->whereHas('tournament', fn (Builder $events): Builder => $events->where('status', '!=', 'cancelled'))
                    ->whereExists(fn ($proofs) => $proofs->selectRaw('1')->from('tournament_payment_proofs')
                        ->whereColumn('tournament_registration_id', 'tournament_registrations.id')->where('status', 'pending'))),
            ])
            ->recordActions([
                Action::make('review_proof')->label('Review proof')
                    ->visible(fn (TournamentRegistration $record): bool => $record->status === 'unpaid' && $record->proof_status === 'pending'
                        && $record->tournament->status !== 'cancelled')
                    ->fillForm(fn (TournamentRegistration $record): array => [
                        'version' => app(TournamentPaymentProofService::class)->currentProof($record)?->version,
                    ])
                    ->modalContent(function (TournamentRegistration $record, Action $action) {
                        $proof = app(TournamentPaymentProofService::class)->currentProof($record);
                        $version = $action->getRawData()['version'] ?? null;

                        return view('filament.tournament-payment-proof', [
                            'record' => $record,
                            'proof' => $proof?->version === $version ? $proof : null,
                        ]);
                    })
                    ->schema([
                        Hidden::make('version')->required()->rules(['uuid']),
                        Select::make('decision')->options(fn (TournamentRegistration $record): array => $record->tournament->ends_at->gt(now()->subDays(30))
                            ? ['approve' => 'Approve payment', 'reject' => 'Reject proof']
                            : ['reject' => 'Reject proof'])
                            ->rules(['in:approve,reject'])->required()->live(),
                        Textarea::make('rejection_reason')->label('Reason shown to the player')->maxLength(500)
                            ->visible(fn (Get $get): bool => $get('decision') === 'reject')
                            ->required(),
                    ])
                    ->action(function (TournamentRegistration $record, array $data, Action $action): void {
                        try {
                            app(TournamentPaymentProofService::class)->review(
                                $record, auth()->user(), $data['version'], $data['decision'] === 'reject' ? $data['rejection_reason'] : null,
                            );
                        } catch (ValidationException $exception) {
                            Notification::make()->danger()->title('Payment review could not be saved')->body($exception->getMessage())->send();
                            $action->halt();
                        }
                    }),
                Action::make('cancelled')->label('Cancel registration')
                    ->modalDescription('Cancellation releases the seat. Any refund must be handled separately by staff.')
                    ->requiresConfirmation()->visible(fn ($record) => $record->status !== 'cancelled')
                    ->action(fn ($record) => app(TournamentService::class)->cancelRegistration($record, auth()->user())),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageTournamentRegistrations::route('/')];
    }
}
