<?php

namespace Database\Seeders;

use App\Actions\Transfers\ReceiveTransferAction;
use App\Actions\Transfers\SendTransferAction;
use App\Actions\UsedTyres\UsedTyreActions;
use App\Enums\ProductKind;
use App\Enums\RoleName;
use App\Enums\UsedTyreCheck;
use App\Enums\UsedTyreOrigin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Site;
use App\Models\User;
use App\Services\Stock\LotInput;
use App\Services\Stock\StockService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demonstration accounts (one per role). NEVER run in production.
 * Usage: php artisan db:seed --class=DemoSeeder
 * All demo accounts use the password "demo-password" and the domain demo.test.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(__('app.errors.production_demo'));
        }

        $this->call(DatabaseSeeder::class);

        $dep = Site::query()->where('code', 'DEP')->firstOrFail();
        $m01 = Site::query()->where('code', 'M01')->firstOrFail();
        $m02 = Site::query()->where('code', 'M02')->firstOrFail();

        $accounts = [
            ['owner@demo.test', 'Démo Propriétaire', RoleName::Owner, [$m01, $dep, $m02], 'fr'],
            ['manager.m01@demo.test', 'Démo Responsable M01', RoleName::SiteManager, [$m01], 'fr'],
            ['seller.m01@demo.test', 'Démo Vendeur M01', RoleName::Seller, [$m01], 'fr'],
            ['seller.m02@demo.test', 'Démo Vendeur M02', RoleName::Seller, [$m02], 'ar'],
            ['storekeeper@demo.test', 'Démo Magasinier', RoleName::Storekeeper, [$dep], 'fr'],
            ['tech.m01@demo.test', 'Démo Technicien M01', RoleName::Technician, [$m01], 'ar'],
        ];

        foreach ($accounts as [$email, $name, $role, $sites, $locale]) {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'demo-password',
                'locale' => $locale,
                'default_site_id' => $sites[0]->id,
            ]);
            $user->syncRoles([$role->value]);
            $user->sites()->sync(array_map(fn (Site $s) => $s->id, $sites));
        }

        $this->demoCatalogue($dep, $m01, $m02);
    }

    /** A handful of demo products with stock, a transfer and used tyres. */
    private function demoCatalogue(Site $dep, Site $m01, Site $m02): void
    {
        if (Product::query()->where('name', 'like', '[DEMO]%')->exists()) {
            return;
        }

        $owner = User::query()->where('email', 'owner@demo.test')->firstOrFail();
        auth()->setUser($owner);
        $stock = app(StockService::class);
        $category = fn (string $name) => Category::query()->where('name', $name)->firstOrFail()->id;
        $michelin = Brand::query()->firstOrCreate(['name' => 'Michelin (démo)']);
        $total = Brand::query()->firstOrCreate(['name' => 'Total (démo)']);

        $tyres = [];
        foreach ([[205, 55, '16', '91', 'V', 85000, 52000], [195, 65, '15', '91', 'H', 65000, 39000], [225, 45, '17', '94', 'W', 120000, 76000]] as [$w, $r, $d, $li, $si, $price, $cost]) {
            $p = Product::query()->create([
                'kind' => ProductKind::Goods, 'category_id' => $category('Tourisme'), 'brand_id' => $michelin->id,
                'name' => "[DEMO] Michelin Primacy {$w}/{$r} R{$d} {$li}{$si}", 'price_retail_cents' => $price, 'reference_cost_cents' => $cost,
            ]);
            $p->tyreSpec()->create(['width' => $w, 'aspect_ratio' => $r, 'rim_diameter' => $d, 'load_index' => $li, 'speed_index' => $si, 'season' => 'summer', 'pattern' => 'Primacy 4']);
            $p->thresholds()->create(['site_id' => $m01->id, 'min_quantity' => 8]);
            $stock->receive($p, $dep, 40, $cost, lot: new LotInput(12, 2023));
            $stock->receive($p, $dep, 20, $cost, lot: new LotInput(8, 2026));
            $tyres[] = $p;
        }

        $oil = Product::query()->create([
            'kind' => ProductKind::Goods, 'category_id' => $category('Huiles moteur'), 'brand_id' => $total->id, 'unit' => 'can',
            'name' => '[DEMO] Total Quartz 9000 5W40 5L', 'price_retail_cents' => 42000, 'reference_cost_cents' => 30000,
        ]);
        $oil->oilSpec()->create(['viscosity' => '5W40', 'oil_type' => 'synthetic', 'volume_ml' => 5000, 'standards' => 'ACEA A3/B4']);
        $stock->receive($oil, $dep, 30, 30000);

        Product::query()->create([
            'kind' => ProductKind::Service, 'category_id' => $category('Pneumatique'),
            'name' => '[DEMO] Montage + équilibrage', 'name_ar' => '[DEMO] تركيب + توازن', 'price_retail_cents' => 5000, 'duration_minutes' => 20,
        ])->priceTiers()->createMany([
            ['min_rim_diameter' => 13, 'max_rim_diameter' => 15, 'price_cents' => 4000],
            ['min_rim_diameter' => 16, 'max_rim_diameter' => 17, 'price_cents' => 5000],
            ['min_rim_diameter' => 18, 'max_rim_diameter' => null, 'price_cents' => 7000],
        ]);

        $transfer = app(SendTransferAction::class)->execute($dep, $m01, [$tyres[0]->id => 4, $oil->id => 6], $owner);
        app(ReceiveTransferAction::class)->execute($transfer, $owner);
        app(SendTransferAction::class)->execute($dep, $m02, [$tyres[1]->id => 4], $owner);

        $usedTyres = app(UsedTyreActions::class);
        $ok = collect(UsedTyreCheck::cases())->mapWithKeys(fn ($c) => [$c->value => true])->all();
        foreach ([52, 55, 58, 60] as $depth) {
            $t = $usedTyres->register(['width' => 205, 'aspect_ratio' => 55, 'rim_diameter' => '16', 'brand_id' => $michelin->id, 'pattern' => 'Primacy 4',
                'tread_depth_tenths' => $depth, 'price_cents' => 30000, 'cost_cents' => 12000], $m01, UsedTyreOrigin::Supplier, $owner);
            $usedTyres->inspect($t, $ok, $owner, $depth);
        }
        $usedTyres->register(['width' => 195, 'aspect_ratio' => 65, 'rim_diameter' => '15', 'tread_depth_tenths' => 40], $m01, UsedTyreOrigin::TradeIn, $owner);
    }
}
