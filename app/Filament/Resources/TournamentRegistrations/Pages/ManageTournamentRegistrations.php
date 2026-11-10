<?php

namespace App\Filament\Resources\TournamentRegistrations\Pages;

use App\Filament\Resources\TournamentRegistrations\TournamentRegistrationResource;
use Filament\Resources\Pages\ManageRecords;

class ManageTournamentRegistrations extends ManageRecords
{
    protected static string $resource = TournamentRegistrationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
