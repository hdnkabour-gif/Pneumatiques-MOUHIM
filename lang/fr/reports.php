<?php

return [
    'title' => 'Rapports',
    'excel' => 'Excel',
    'all_sites' => 'Tous les sites',
    'periods' => ['today' => 'Aujourd\'hui', 'week' => 'Cette semaine', 'month' => 'Ce mois', 'last_month' => 'Mois dernier', 'year' => 'Cette année'],
    'by' => ['site' => 'Par site', 'seller' => 'Par vendeur', 'category' => 'Par catégorie', 'brand' => 'Par marque'],
    'cols' => ['label' => 'Nom', 'docs' => 'Ventes', 'revenue' => 'Chiffre d\'affaires TTC', 'basket' => 'Panier moyen', 'margin' => 'Marge', 'qty' => 'Qté', 'customer' => 'Client', 'value' => 'Valeur (coût moyen)'],
    'top' => '10 produits les plus vendus',
    'aged' => 'Balance âgée des clients',
    'stock' => 'Valeur du stock',
    'unknown_cost' => ':count sans coût',
    'export' => [
        'title' => 'Export comptable',
        'download' => 'Télécharger le mois',
        'month' => 'Mois',
        'help' => 'Un fichier Excel par mois pour le comptable : journal des ventes et avoirs (HT, TVA, TTC), TVA collectée par taux, achats fournisseurs, encaissements (avec l\'état des chèques) et dépenses. Le format est à valider avec votre comptable (D-30).',
    ],
];
