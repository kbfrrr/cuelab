<?php

namespace App\Filament\Resources\StaffActions\Pages;

use App\Filament\Resources\StaffActions\StaffActionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageStaffActions extends ManageRecords
{
    protected static string $resource = StaffActionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
