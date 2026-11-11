<?php

namespace App\Filament\Pages;

use App\Enums\CompatibilityStatus;
use App\Enums\Permission;
use App\Filament\Support\PageHub;
use App\Filament\Support\VehicleFields;
use App\Models\OilFitment;
use App\Models\Product;
use App\Models\Site;
use App\Models\TyreFitment;
use App\Models\VehicleModel;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Vehicle → compatible tyre sizes and oils, with matching products and stock
 * per site (§7.3). Only verified compatibilities are presented as compatible;
 * the others appear, clearly separated, to authorised users only.
 */
class VehicleLookup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?int $navigationSort = 50;

    public ?int $modelId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->canAny([Permission::CompatibilitiesView->value, Permission::CompatibilitiesManage->value, Permission::CompatibilitiesVerify->value]) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.sales');
    }

    public static function getNavigationLabel(): string
    {
        return __('vehicles.lookup.title');
    }

    public function getTitle(): string
    {
        return __('vehicles.lookup.title');
    }

    public function content(Schema $schema): Schema
    {
        $components = [
            Select::make('modelId')->hiddenLabel()->placeholder(__('vehicles.lookup.pick'))
                ->options(fn () => VehicleFields::modelOptions())->searchable()->live(),
        ];

        if ($this->modelId && ($model = VehicleModel::query()->with('make')->find($this->modelId))) {
            $tyres = TyreFitment::query()->with(['generation', 'source'])->where('model_id', $model->id)->get();
            $oils = OilFitment::query()->with(['generation', 'source'])->where('model_id', $model->id)->get();
            $verifiedTyres = $tyres->where('status', CompatibilityStatus::Verified);
            $verifiedOils = $oils->where('status', CompatibilityStatus::Verified);

            $verified = [...$verifiedTyres->map(fn (TyreFitment $f) => $this->tyreBlock($f))->all(), ...$verifiedOils->map(fn (OilFitment $f) => $this->oilBlock($f))->all()];
            $components[] = Section::make(__('vehicles.lookup.verified_title').' – '.$model->fullName())
                ->schema($verified ?: [Text::make(__('vehicles.lookup.none_verified'))]);

            if (VehicleFields::canSeeUnverified()) {
                $others = [...$tyres->where('status', '!=', CompatibilityStatus::Verified)->where('status', '!=', CompatibilityStatus::Obsolete)->map(fn ($f) => $this->tyreBlock($f, false))->all(),
                    ...$oils->where('status', '!=', CompatibilityStatus::Verified)->where('status', '!=', CompatibilityStatus::Obsolete)->map(fn ($f) => $this->oilBlock($f, false))->all()];
                if ($others) {
                    $components[] = Section::make(__('vehicles.lookup.unverified_title'))->collapsible()->schema($others);
                }
            }
            $components[] = Text::make(__('vehicles.lookup.history_note'))->color('gray');
        }

        return $schema->components($components);
    }

    private function tyreBlock(TyreFitment $f, bool $withProducts = true): Section
    {
        $title = $f->sizeLabel().($f->trim ? ' – '.$f->trim : '').' · '.$f->status->label();

        return Section::make($title)->description($f->source?->label())->compact()
            ->schema($withProducts ? $this->productLines($f->matchingProducts()->with('stockLevels')->get()) : []);
    }

    private function oilBlock(OilFitment $f, bool $withProducts = true): Section
    {
        $norm = trim(implode(' · ', array_filter([$f->viscosity, $f->acea ? 'ACEA '.$f->acea : null, $f->api ? 'API '.$f->api : null, $f->oem_approval, $f->capacityLitres() ? $f->capacityLitres().' L' : null])));
        $title = __('vehicles.fields.viscosity').' : '.($norm ?: '—').($f->engine_label ? ' – '.$f->engine_label : '').' · '.$f->status->label();

        return Section::make($title)->description($f->source?->label())->compact()
            ->schema($withProducts && $f->viscosity ? $this->productLines($f->matchingProducts()->with('stockLevels')->get()) : []);
    }

    /** @param  Collection<int, Product>  $products */
    private function productLines(Collection $products): array
    {
        if ($products->isEmpty()) {
            return [Text::make(__('vehicles.lookup.no_product'))->color('gray')];
        }

        $sites = Site::query()->visibleTo(auth()->user())->orderBy('code')->get();

        return $products->map(fn (Product $p) => Text::make(sprintf('%s — %s — %s',
            $p->name,
            $sites->map(fn (Site $s) => $s->code.' '.(int) ($p->stockLevels->firstWhere('site_id', $s->id)?->quantity ?? 0))->implode(' · '),
            Money::format($p->price_retail_cents),
        )))->all();
    }

    protected function getHeaderActions(): array
    {
        return PageHub::actions(static::class);
    }
}
