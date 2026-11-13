<?php

namespace App\Filament\Pages;

use Filament\Auth\Pages\Login;
use Livewire\Attributes\Unexposed;

class StaffLogin extends Login
{
    public function getHeading(): string
    {
        return 'Staff sign in';
    }

    #[Unexposed]
    public function getExtraBodyAttributes(): array
    {
        return [...parent::getExtraBodyAttributes(), 'data-cuelab-page' => 'staff-login'];
    }
}
