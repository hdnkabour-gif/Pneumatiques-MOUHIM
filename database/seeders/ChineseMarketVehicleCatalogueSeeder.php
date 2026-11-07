<?php

namespace Database\Seeders;

use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;

/**
 * Confirmed Chinese-group vehicle marques/models introduced or actively
 * marketed in Morocco during 2024-2026.
 *
 * Catalogue only: no generations, engines, tyre/oil fitments, prices, SKUs,
 * stock, or verified mechanical compatibility are created.
 * Existing makes/models and owner-entered notes are never overwritten.
 */
class ChineseMarketVehicleCatalogueSeeder extends Seeder
{
    /**
     * @var array<string, array{source: string, models: list<string>}>
     */
    public const CATALOGUE = [
        'AVATR' => [
            'source' => 'https://www.moteur.ma/fr/autonews/actualites-maroc/avatr-fait-officiellement-son-entree-au-maroc-avec-trois-modeles-haut-de-gamme-1863.html',
            'models' => ['07', '11', '12'],
        ],
        'GAC' => [
            'source' => 'https://autonews.ma/actualite-auto/actualite-nationale/24874-gac-maroc-prix-et-equipements-des-emzoom-emkoo-et-s7',
            'models' => ['Emzoom', 'Emkoo', 'S7', 'GN8', 'HYPTEC HT', 'HYPTEC Hyper SSR'],
        ],
        'XPeng' => [
            'source' => 'https://lematin.ma/automobile/ouverture-du-premier-showroom-xpeng-a-casablanca/338984',
            'models' => ['G6', 'G9'],
        ],
        'Jetour' => [
            'source' => 'https://medias24.com/2026/04/15/jetour-officialise-son-arrivee-au-maroc-avec-deux-nouveaux-suv-le-t1-et-le-g700-1660153/',
            'models' => ['Dashing', 'T1', 'T2', 'G700'],
        ],
        'Soueast' => [
            'source' => 'https://medias24.com/2026/06/10/soueast-lance-le-s08-dm-au-maroc-un-suv-de-7-places-1696729/',
            'models' => ['S06', 'S07', 'S08 DM', 'S09'],
        ],
        'Lynk & Co' => [
            'source' => 'https://2m.ma/fr/news/La-marque-europ%C3%A9enne-Lynk-Co-lance-officiellement-ses-v%C3%A9hicules-20250911',
            'models' => ['01', '02', '06', '08'],
        ],
        'OMODA' => [
            'source' => 'https://www.cfaogroup.com/cfao-mobility-au-maroc-distribution-des-marques-omoda-et-jaecoo/',
            'models' => ['C5'],
        ],
        'JAECOO' => [
            'source' => 'https://www.cfaogroup.com/cfao-mobility-au-maroc-distribution-des-marques-omoda-et-jaecoo/',
            'models' => ['7'],
        ],
        'DEEPAL' => [
            'source' => 'https://deepal.ma/',
            'models' => ['S05', 'S07', 'G318'],
        ],
    ];

    public function run(): void
    {
        foreach (self::CATALOGUE as $makeName => $entry) {
            $make = VehicleMake::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($makeName)])
                ->first();

            if (! $make) {
                $make = VehicleMake::query()->create(['name' => $makeName, 'is_active' => true]);
            }

            foreach ($entry['models'] as $modelName) {
                $exists = VehicleModel::query()
                    ->where('make_id', $make->id)
                    ->whereRaw('lower(name) = ?', [mb_strtolower($modelName)])
                    ->exists();

                if ($exists) {
                    continue;
                }

                VehicleModel::query()->create([
                    'make_id' => $make->id,
                    'name' => $modelName,
                    'notes' => 'Référencement Maroc confirmé / gamme présentée. Source marché : '.$entry['source'].'. Génération, motorisation, années, dimensions et compatibilités restent à vérifier pour la version exacte.',
                ]);
            }
        }
    }
}
