<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\BilliardTable;
use App\Services\ReservationService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Str;

class ManageReservations extends ManageRecords
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('walk_in')->label('Record walk-in')->schema([
            Select::make('billiard_table_id')->label('Table')->options(BilliardTable::where('is_active', true)->pluck('name', 'id'))->required(),
            DateTimePicker::make('starts_at')->default(now())->seconds(false)->required(),
            TextInput::make('minutes')->integer()->minValue(15)->maxValue(480)->default(60)->required(),
            TextInput::make('customer_name')->required()->maxLength(150),
            TextInput::make('phone')->required()->maxLength(32),
        ])->action(function (array $data): void {
            $service = app(ReservationService::class);
            $quote = $service->quote((int) $data['billiard_table_id'], $data['starts_at'], (int) $data['minutes'], true);
            $service->create([...$data, 'hourly_rate' => $quote['hourly_rate'], 'submission_key' => (string) Str::uuid()], auth()->user());
        })];
    }
}
