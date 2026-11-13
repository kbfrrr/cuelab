<?php

namespace App\Filament\Resources\BilliardTables\Pages;

use App\Filament\Resources\BilliardTables\BilliardTableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBilliardTables extends ManageRecords
{
    protected static string $resource = BilliardTableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(fn (array $data): array => [...$data, 'type' => 'Standard']),
        ];
    }
}
