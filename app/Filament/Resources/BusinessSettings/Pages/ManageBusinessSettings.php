<?php

namespace App\Filament\Resources\BusinessSettings\Pages;

use App\Filament\Resources\BusinessSettings\BusinessSettingResource;
use Filament\Resources\Pages\ManageRecords;

class ManageBusinessSettings extends ManageRecords
{
    protected static string $resource = BusinessSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
