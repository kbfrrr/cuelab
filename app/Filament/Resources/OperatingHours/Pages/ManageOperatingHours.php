<?php

namespace App\Filament\Resources\OperatingHours\Pages;

use App\Filament\Resources\OperatingHours\OperatingHourResource;
use Filament\Resources\Pages\ManageRecords;

class ManageOperatingHours extends ManageRecords
{
    protected static string $resource = OperatingHourResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
