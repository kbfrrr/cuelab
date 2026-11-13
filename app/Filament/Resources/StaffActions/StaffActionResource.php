<?php

namespace App\Filament\Resources\StaffActions;

use App\Filament\Resources\StaffActions\Pages\ManageStaffActions;
use App\Models\StaffAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StaffActionResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 9;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $model = StaffAction::class;

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
        return $table
            ->stackedOnMobile()
            ->searchPlaceholder('Staff member, action, or record ID')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('M j, Y · g:i A')->sortable()->wrap(),
                TextColumn::make('user.name')->label('Staff member')->placeholder('Account unavailable')->searchable()->sortable()->wrap(),
                TextColumn::make('action')->label('Activity')->formatStateUsing(fn (StaffAction $record): string => static::activityLabel($record))
                    ->description(fn (StaffAction $record): ?string => static::changeSummary($record))
                    ->searchable()->sortable()->wrap(),
                TextColumn::make('subject_type')->label('Record')
                    ->formatStateUsing(fn (string $state): string => Str::headline(class_basename($state)))
                    ->description(fn (StaffAction $record): ?string => $record->subject_id === null ? null : '#'.$record->subject_id)
                    ->placeholder('No record')
                    ->searchable(['subject_type', 'subject_id'])
                    ->sortable()->wrap(),
            ])
            ->defaultSort('id', 'desc');
    }

    private static function activityLabel(StaffAction $record): string
    {
        [$subject, $event] = array_pad(explode('.', $record->action, 2), 2, null);
        if ($event === null) {
            return Str::headline($subject);
        }

        $subject = match ($subject) {
            'settings', 'business_settings' => 'business setting',
            'tournament_payment_proof' => 'payment proof',
            default => Str::lower(Str::headline(Str::singular($subject))),
        };

        return Str::headline($event).' '.$subject;
    }

    private static function changeSummary(StaffAction $record): ?string
    {
        $metadata = $record->metadata ?? [];
        $fields = $metadata['fields'] ?? [];

        if (! str_ends_with($record->action, '.updated') || ! is_array($fields)) {
            return null;
        }

        $labels = [];
        foreach ($fields as $field) {
            if (! is_string($field) || in_array($field, ['updated_at', 'created_at'], true)) {
                continue;
            }

            $labels[] = match ($field) {
                'status' => match ($metadata['status'] ?? null) {
                    'pending' => 'Status → Pending approval',
                    'no_show' => 'Status → No-show',
                    default => is_string($metadata['status'] ?? null) ? 'Status → '.Str::headline($metadata['status']) : 'Status',
                },
                'amount_minor' => 'Hourly rate',
                'entry_fee_minor' => 'Entry fee',
                'is_verified' => 'Verification',
                'is_active' => 'Active status',
                'value' => 'Setting value',
                default => Str::headline($field),
            };
        }

        if ($labels === []) {
            return null;
        }

        $visible = array_slice($labels, 0, 3);
        $remaining = count($labels) - count($visible);

        return implode(' · ', $visible).($remaining > 0 ? " · +{$remaining} more" : '');
    }

    public static function getPages(): array
    {
        return ['index' => ManageStaffActions::route('/')];
    }
}
