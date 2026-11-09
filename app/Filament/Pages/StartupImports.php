<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Services\Imports\StartupImport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Start-up (§17): customers with their old debt, products, opening stock — from Excel. */
class StartupImports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 93;

    protected string $view = 'filament.pages.startup-imports';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'imports';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('imports.title');
    }

    public function getTitle(): string
    {
        return __('imports.title');
    }

    protected function getHeaderActions(): array
    {
        $actions = [];
        foreach (array_keys(StartupImport::TYPES) as $type) {
            $actions[] = Action::make('template_'.$type)->label(__('imports.template', ['type' => __('imports.types.'.$type)]))
                ->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(function () use ($type) {
                    $path = storage_path('app/template-'.uniqid().'.xlsx');
                    app(StartupImport::class)->template($type, $path);

                    return response()->download($path, 'modele-'.$type.'.xlsx')->deleteFileAfterSend();
                });
            $actions[] = Action::make('import_'.$type)->label(__('imports.import', ['type' => __('imports.types.'.$type)]))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->schema([FileUpload::make('file')->label(__('imports.file'))->required()->disk('local')->directory('imports')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'])])
                ->action(function (array $data) use ($type) {
                    $path = Storage::disk('local')->path($data['file']);
                    try {
                        $service = app(StartupImport::class);
                        $checked = $service->check($type, $path);
                        if ($checked['errors'] !== []) {
                            Notification::make()->danger()->persistent()->title(__('imports.has_errors', ['count' => count($checked['errors'])]))
                                ->body(implode('<br>', array_map('e', array_slice($checked['errors'], 0, 15))))->send();

                            return;
                        }
                        $result = $service->import($type, $checked['rows'], auth()->user());
                        Notification::make()->success()->persistent()->title(__('imports.done', $result))->send();
                    } catch (Throwable $e) {
                        report($e);
                        Notification::make()->danger()->title(__('imports.unreadable'))->send();
                    } finally {
                        Storage::disk('local')->delete($data['file']);
                    }
                });
        }

        return $actions;
    }
}
