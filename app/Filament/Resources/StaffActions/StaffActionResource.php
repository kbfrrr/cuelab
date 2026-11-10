<?php

namespace App\Filament\Resources\StaffActions;

use App\Filament\Resources\StaffActions\Pages\ManageStaffActions;
use App\Models\StaffAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StaffActionResource extends Resource
{
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
        return $table->columns([TextColumn::make('user.name')->searchable()->sortable(),
            TextColumn::make('action')->searchable()->sortable(),
            TextColumn::make('subject_type')->searchable()->sortable(),
            TextColumn::make('subject_id')->searchable()->sortable(),
            TextColumn::make('created_at')->searchable()->sortable()])->filters([])->recordActions([])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageStaffActions::route('/')];
    }
}
