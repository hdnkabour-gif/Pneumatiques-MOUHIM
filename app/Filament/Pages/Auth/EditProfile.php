<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Components\Component;

/** Profile page: name, email, password for everyone; two-factor section for owners only. */
class EditProfile extends BaseEditProfile
{
    public function getMultiFactorAuthenticationContentComponent(): ?Component
    {
        if (! auth()->user()?->requiresTwoFactor()) {
            return null;
        }

        return parent::getMultiFactorAuthenticationContentComponent();
    }
}
