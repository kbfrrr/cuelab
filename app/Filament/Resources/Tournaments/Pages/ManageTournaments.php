<?php

namespace App\Filament\Resources\Tournaments\Pages;

use App\Filament\Resources\Tournaments\TournamentResource;
use App\Services\TournamentService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTournaments extends ManageRecords
{
    protected static string $resource = TournamentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => app(TournamentService::class)->save($data, auth()->user()))];
    }
}
