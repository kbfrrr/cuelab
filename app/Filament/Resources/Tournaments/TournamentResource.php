<?php

namespace App\Filament\Resources\Tournaments;

use App\Filament\Resources\Tournaments\Pages\ManageTournaments;
use App\Models\Tournament;
use App\Services\TournamentService;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TournamentResource extends Resource
{
    protected static ?string $model = Tournament::class;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_staff;
    }

    public static function canCreate(): bool
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
        return $schema->components([TextInput::make('title')->required()->maxLength(150), Textarea::make('description'), Textarea::make('rules')->required(), DateTimePicker::make('starts_at')->required(), DateTimePicker::make('ends_at')->required(), DateTimePicker::make('registration_deadline')->required(), TextInput::make('capacity')->integer()->minValue(1)->required(), TextInput::make('entry_fee_minor')->label('Entry fee')->prefix('PHP')->numeric()->default(0)->formatStateUsing(fn ($state) => $state / 100)->dehydrateStateUsing(fn ($state) => (int) round($state * 100))->required(), Select::make('status')->helperText('Publishing reserves all venue tables for the event. Closing registration keeps that block; cancelling releases it.')->options(['draft' => 'Draft', 'published' => 'Published', 'closed' => 'Registration closed', 'cancelled' => 'Cancelled'])->default('draft')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->searchable(), TextColumn::make('starts_at')->dateTime(), TextColumn::make('status')->badge(), TextColumn::make('capacity')])->recordActions([EditAction::make()->using(fn ($record, array $data) => app(TournamentService::class)->save($data, auth()->user(), $record))])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageTournaments::route('/')];
    }
}
