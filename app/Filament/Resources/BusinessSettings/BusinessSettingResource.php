<?php

namespace App\Filament\Resources\BusinessSettings;

use App\Filament\Resources\BusinessSettings\Pages\ManageBusinessSettings;
use App\Models\BusinessSetting;
use App\Services\TournamentPaymentProofService;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BusinessSettingResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 8;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $model = BusinessSetting::class;

    private static function settingLabel(string $key): string
    {
        return match ($key) {
            'booking_enabled' => 'Online bookings',
            'hold_minutes' => 'Pending booking hold',
            'address' => 'Venue address',
            'phone' => 'Venue phone number',
            'social_url' => 'Social media link',
            'house_rules' => 'House rules',
            TournamentPaymentProofService::RECIPIENT_NAME => 'GCash recipient name',
            TournamentPaymentProofService::RECIPIENT_NUMBER => 'GCash mobile number',
            default => Str::headline($key),
        };
    }

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
        return $schema->components([TextInput::make('value')->label(fn (?BusinessSetting $record): string => static::settingLabel($record?->key ?? 'value'))->helperText(fn ($record) => match ($record?->key) {
            TournamentPaymentProofService::RECIPIENT_NAME => 'Enter the name shown on CueLab’s GCash account.',
            TournamentPaymentProofService::RECIPIENT_NUMBER => 'Enter the GCash mobile number in 09XXXXXXXXX or +639XXXXXXXXX format.',
            'booking_enabled' => 'Enter true to accept online bookings or false to pause them.',
            'hold_minutes' => 'How long a pending booking holds a table. Enter 1–1440 minutes.',
            default => null,
        })->formatStateUsing(fn ($state) => is_bool($state) ? ($state ? 'true' : 'false') : $state)->rules(fn ($record) => match ($record?->key) {
            'booking_enabled' => ['required', 'in:true,false'],
            'hold_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            TournamentPaymentProofService::RECIPIENT_NAME => ['nullable', 'string', 'max:150'],
            TournamentPaymentProofService::RECIPIENT_NUMBER => ['nullable', 'regex:'.TournamentPaymentProofService::MOBILE_PATTERN],
            default => ['nullable', 'string', 'max:2000'],
        })->dehydrateStateUsing(fn ($state, $record) => match ($record?->key) {
            'booking_enabled' => $state === 'true', 'hold_minutes' => (int) $state, default => $state
        }), Toggle::make('is_verified')->label('Verified by venue')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('key')->label('Setting')->formatStateUsing(fn (string $state): string => static::settingLabel($state))->sortable()
            ->searchable(query: function (Builder $query, string $search): Builder {
                $keys = BusinessSetting::query()->pluck('key')->filter(fn (string $key): bool => Str::contains(static::settingLabel($key), $search, ignoreCase: true));

                return $query->where(fn (Builder $query): Builder => $query->whereLike('key', "%{$search}%")->orWhereIn('key', $keys));
            }),
            TextColumn::make('value')->searchable()->sortable()->placeholder('Not set')
                ->formatStateUsing(fn ($state, BusinessSetting $record) => match ($record->key) {
                    'booking_enabled' => filter_var($record->value, FILTER_VALIDATE_BOOL) ? 'Enabled' : 'Paused',
                    'hold_minutes' => $state.' min',
                    default => $state,
                }),
            TextColumn::make('is_verified')->label('Verification')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Verified' : 'Unverified')
                ->color(fn (bool $state): string => $state ? 'success' : 'warning')->sortable()])->filters([])
            ->recordActions([EditAction::make()])
            ->recordActionsColumnLabel('Actions')
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageBusinessSettings::route('/')];
    }
}
