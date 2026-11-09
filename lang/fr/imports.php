<?php

return [
    'title' => 'Imports de démarrage',
    'help' => 'Téléchargez le modèle, remplissez-le dans Excel, puis importez-le. Chaque ligne est vérifiée : si une seule est fausse, rien n\'est importé et la liste des erreurs s\'affiche. Les clients et produits déjà présents ne sont jamais écrasés.',
    'steps' => [
        'customers' => 'Clients : nom, téléphone, type, ICE, ville, plafond de crédit (propriétaire), dette existante et sa date, immatriculation.',
        'products' => 'Produits : nom (ou dimension pour un pneu), catégorie exacte, marque, prix, coût, unité.',
        'stock' => 'Stock d\'ouverture : SKU, site, quantité, coût, DOT pour les pneus (après l\'import des produits).',
    ],
    'types' => ['customers' => 'clients', 'products' => 'produits', 'stock' => 'stock d\'ouverture'],
    'template' => 'Modèle :type',
    'import' => 'Importer :type',
    'file' => 'Fichier Excel rempli',
    'line' => 'Ligne :line : :error',
    'empty' => 'Le fichier ne contient aucune ligne.',
    'has_errors' => ':count erreur(s) : rien n\'a été importé',
    'done' => 'Import terminé : :created créé(s), :skipped déjà présent(s).',
    'unreadable' => 'Fichier illisible : utilisez le modèle téléchargé, enregistré au format .xlsx.',
    'errors' => [
        'required' => '« :column » est obligatoire.',
        'phone' => 'téléphone incomplet.',
        'amount' => 'montant invalide dans « :column ».',
        'date' => 'date du solde attendue au format JJ/MM/AAAA.',
        'category' => 'catégorie « :name » inconnue (voir Catalogue → Catégories).',
        'unit' => 'unité inconnue (piece, can, kit, set ou litre).',
        'sku' => 'SKU « :sku » introuvable (importez d\'abord les produits).',
        'site' => 'site inconnu (DEP, M01 ou M02).',
        'quantity' => 'quantité entière supérieure à 0 attendue.',
    ],
];
