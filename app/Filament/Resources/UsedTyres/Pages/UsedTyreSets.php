<?php

namespace App\Filament\Resources\UsedTyres\Pages;

use App\Filament\Resources\UsedTyres\UsedTyreResource;
use App\Models\UsedTyre;
use App\Services\Stock\UsedTyreSetFinder;
use App\Support\Money;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/** Matching sets of 4 and 2 sellable used tyres (§5.4, D-43). */
class UsedTyreSets extends Page
{
    protected static string $resource = UsedTyreResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('used_tyres.sections.sets');
    }

    public function content(Schema $schema): Schema
    {
        $finder = app(UsedTyreSetFinder::class);
        $user = auth()->user();
        $sites = $user->canSeeAllSites() ? [null] : $user->siteIds();

        $sections = [];
        foreach ([4, 2] as $size) {
            $sets = collect($sites)->flatMap(fn ($siteId) => $finder->find($size, $siteId));
            $sections[] = Section::make(__('used_tyres.fields.set_size').' '.$size)
                ->schema($sets->isEmpty()
                    ? [Text::make(__('used_tyres.messages.no_sets'))]
                    : $sets->map(fn (Collection $set) => Text::make($this->describe($set)))->all());
        }

        return $schema->components($sections);
    }

    /** @param  Collection<int, UsedTyre>  $set */
    private function describe(Collection $set): string
    {
        $first = $set->first();
        $treads = $set->map(fn (UsedTyre $t) => $t->treadDepthMm())->implode(' / ');
        $total = $set->sum(fn (UsedTyre $t) => (int) $t->price_cents);

        return sprintf('%s · %s %s · %s mm · %s · %s · %s',
            $first->sizeLabel(), $first->brand?->name, $first->pattern, $treads, $first->site->code,
            $set->pluck('code')->implode(', '), Money::format($total));
    }
}
