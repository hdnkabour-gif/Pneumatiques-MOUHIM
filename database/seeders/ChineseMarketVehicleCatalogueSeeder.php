<?php

namespace Database\Seeders;

use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;

/**
 * Chinese and China-group-backed marques/models confirmed in the Moroccan
 * market in the 2024-2026 expansion wave.
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
            'source' => 'https://medias24.com/2026/09/29/gac-passe-a-loffensive-au-maroc-avec-trois-nouveaux-modeles-1767009/',
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
            'source' => 'https://medias24.com/guide_voiture_au_maroc/marque/soueast/',
            'models' => ['S06', 'S07', 'S08 DM', 'S09'],
        ],
        'Lynk & Co' => [
            'source' => 'https://2m.ma/fr/news/La-marque-europ%C3%A9enne-Lynk-Co-lance-officiellement-ses-v%C3%A9hicules-20250911',
            'models' => ['01', '02', '06', '08'],
        ],
        'OMODA' => [
            'source' => 'https://www.infomediaire.net/cfao-motors-commercialise-les-marques-chinoises-omoda-jaecoo-au-maroc/',
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
        'BAIC' => [
            'source' => 'https://www.baicmaroc.ma/',
            'models' => ['U5 PLUS', 'X35', 'X55', 'X7', 'BJ30e'],
        ],
        'Dongfeng' => [
            'source' => 'https://chinauto.ma/fr/guide/prix-voitures-chinoises',
            'models' => ['BOX', 'SHINE'],
        ],
        'EXEED' => [
            'source' => 'https://chinauto.ma/fr/guide/prix-voitures-chinoises',
            'models' => ['RX'],
        ],
        'JAC' => [
            'source' => 'https://chinauto.ma/fr/guide/prix-voitures-chinoises',
            'models' => ['E30X'],
        ],
        'Leapmotor' => [
            'source' => 'https://www.leapmotor.net/fr-ma/modeles',
            'models' => ['T03', 'C10'],
        ],
        'Seres' => [
            'source' => 'https://lematin.ma/automobile/les-raisons-de-la-ruee-des-marques-de-voitures-chinoises-vers-le-maroc/348075',
            'models' => ['3'],
        ],
        'Zeekr' => [
            'source' => 'https://www.bornerecharge.ma/wp-content/uploads/2025/09/SUPP-VOITURES-1.pdf',
            'models' => ['001', 'X'],
        ],
        'ROX' => [
            'source' => 'https://roxmotors.ma/',
            'models' => ['01', 'ADAMAS'],
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
