<?php

namespace App\Filament\Actions;

use App\Actions\Vehicles\ImportReferenceWorkbookAction;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/** Owner / authorised user imports the reference workbook (D-48). */
final class ImportVehicleWorkbookAction
{
    public static function make(): Action
    {
        return Action::make('importWorkbook')->label(__('vehicles.actions.import'))->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
            ->visible(fn () => auth()->user()->can(Permission::CompatibilitiesManage->value))
            ->modalDescription(__('vehicles.import.help'))
            ->schema([
                FileUpload::make('file')->label(__('vehicles.fields.file'))->disk('local')->directory('imports')
                    // Phones sometimes label an .xlsx (itself a zip archive) as a .zip: accept both, the importer checks the content.
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/zip', 'application/x-zip-compressed', 'application/octet-stream',
                    ])->maxSize(20480)->required(),
            ])
            ->action(function (array $data) {
                $path = Storage::disk('local')->path($data['file']);
                try {
                    $report = app(ImportReferenceWorkbookAction::class)->execute($path, auth()->user());
                    $lines = collect($report['counts'])->map(fn ($c, $sheet) => __('vehicles.messages.import_report', ['sheet' => $sheet, ...$c]))
                        ->merge($report['errors'])->implode("\n");
                    Notification::make()->success()->title(__('vehicles.messages.imported'))->body(nl2br(e($lines)))->persistent()->send();
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                } finally {
                    Storage::disk('local')->delete($data['file']);
                }
            });
    }
}
