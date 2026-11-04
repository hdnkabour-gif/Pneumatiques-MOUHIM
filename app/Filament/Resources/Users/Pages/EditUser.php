<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\SaveUserAction;
use App\Filament\Concerns\MapsActionValidationErrors;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    use MapsActionValidationErrors;

    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role'] = $this->record->roles->first()?->name;
        $data['sites'] = $this->record->siteIds();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->runAction(fn () => app(SaveUserAction::class)->execute($record, $data, auth()->user()));
    }
}
