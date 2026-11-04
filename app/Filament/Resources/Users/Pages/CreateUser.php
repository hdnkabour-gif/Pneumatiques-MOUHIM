<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\SaveUserAction;
use App\Filament\Concerns\MapsActionValidationErrors;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    use MapsActionValidationErrors;

    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runAction(fn () => app(SaveUserAction::class)->execute(null, $data, auth()->user()));
    }
}
