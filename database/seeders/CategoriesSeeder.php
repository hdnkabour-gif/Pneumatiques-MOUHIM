<?php

namespace Database\Seeders;

use App\Enums\CategoryType;
use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Initial product categories (§5.1). Reference data, safe in production:
 * existing categories are never modified, so the owner's edits are kept.
 * Margins are left empty (D-40).
 */
class CategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $tree = [
            ['Pneus neufs', 'إطارات جديدة', CategoryType::NewTyre, 'PN', [
                ['Tourisme', 'سيارات سياحية'], ['SUV / 4x4', 'SUV / دفع رباعي'], ['Utilitaire', 'نفعية'],
                ['Poids lourd', 'وزن ثقيل'], ['Moto', 'دراجات نارية'],
            ]],
            ['Pneus d\'occasion', 'إطارات مستعملة', CategoryType::UsedTyre, 'PO', []],
            ['Jantes et enjoliveurs', 'جنوط وأغطية', CategoryType::Rim, 'JA', []],
            ['Valves, chambres à air et consommables pneus', 'صمامات وأنابيب ومستهلكات الإطارات', CategoryType::TyreSupply, 'CP', []],
            ['Huiles', 'زيوت', CategoryType::Oil, 'HU', [
                ['Huiles moteur', 'زيوت المحرك'], ['Huiles de boîte / transmission', 'زيوت علبة السرعات'],
            ]],
            ['Liquides et additifs', 'سوائل ومضافات', CategoryType::Fluid, 'LQ', [
                ['Liquide de refroidissement', 'سائل التبريد'], ['Liquide de frein', 'سائل الفرامل'],
                ['Lave-glace', 'سائل الزجاج'], ['Additifs', 'مضافات'],
            ]],
            ['Filtres', 'فلاتر', CategoryType::Filter, 'FI', [
                ['Filtres à huile', 'فلاتر الزيت'], ['Filtres à air', 'فلاتر الهواء'],
                ['Filtres habitacle', 'فلاتر المقصورة'], ['Filtres à carburant', 'فلاتر الوقود'],
            ]],
            ['Batteries', 'بطاريات', CategoryType::Battery, 'BA', []],
            ['Éclairage', 'إنارة', CategoryType::Lighting, 'EC', []],
            ['Balais d\'essuie-glace', 'ماسحات الزجاج', CategoryType::Wiper, 'EG', []],
            ['Freinage', 'الفرامل', CategoryType::Brake, 'FR', [], false], // "si activé" (§5.1)
            ['Accessoires et produits d\'entretien', 'إكسسوارات ومواد العناية', CategoryType::Accessory, 'AC', []],
            ['Prestations', 'خدمات', CategoryType::Service, 'SE', [
                ['Pneumatique', 'الإطارات'], ['Entretien', 'الصيانة'], ['Électricité et éclairage', 'الكهرباء والإنارة'],
            ]],
            ['Forfaits', 'عروض مجمّعة', CategoryType::Bundle, 'FO', []],
            ['Autres', 'أخرى', CategoryType::Other, 'AU', []],
        ];

        foreach ($tree as $i => $row) {
            [$name, $nameAr, $type, $prefix, $children] = $row;
            $active = $row[5] ?? true;

            $parent = Category::query()->firstOrCreate(
                ['parent_id' => null, 'name' => $name],
                ['name_ar' => $nameAr, 'type' => $type, 'sku_prefix' => $prefix, 'sort_order' => $i, 'is_active' => $active],
            );

            foreach ($children as $j => [$childName, $childAr]) {
                Category::query()->firstOrCreate(
                    ['parent_id' => $parent->id, 'name' => $childName],
                    ['name_ar' => $childAr, 'type' => $type, 'sku_prefix' => $prefix, 'sort_order' => $j, 'is_active' => $active],
                );
            }
        }
    }
}
