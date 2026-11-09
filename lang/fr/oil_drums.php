<?php

return [
    'label' => 'fût',
    'plural' => 'Fûts d\'huile',
    'fields' => [
        'code' => 'N° fût', 'product' => 'Huile (vrac)', 'site' => 'Site', 'status' => 'État',
        'capacity' => 'Capacité (L)', 'received' => 'Quantité reçue (L)', 'remaining' => 'Reste', 'used' => 'Consommé',
        'purchase_cost' => 'Prix d\'achat du fût', 'extra_cost' => 'Frais (transport…)', 'cost_per_litre' => 'Coût du litre',
        'value' => 'Valeur restante', 'supplier' => 'Fournisseur', 'invoice_ref' => 'N° facture fournisseur',
        'received_at' => 'Reçu le', 'opened_at' => 'Ouvert le', 'notes' => 'Remarques', 'litres' => 'Litres',
        'reason' => 'Motif', 'to_site' => 'Vers le site', 'movements' => 'Mouvements', 'type' => 'Mouvement', 'date' => 'Date', 'user' => 'Par',
    ],
    'received_help' => 'Si la quantité réellement reçue diffère de la capacité, indiquez-la : le coût du litre est calculé sur la quantité reçue.',
    'actions' => ['receive' => 'Réceptionner un fût', 'adjust' => 'Corriger (jauge)', 'transfer' => 'Transférer', 'block' => 'Bloquer', 'unblock' => 'Débloquer'],
    'product_help' => 'Produits dont l\'unité est « Litre (vrac, au fût) ». Prix de vente = prix du litre.',
    'adjust_litres' => 'Litres à ajouter (+) ou retirer (−)',
    'adjust_help' => 'Exemple : -1,5 si la jauge montre 1,5 L de moins.',
    'low' => 'Bientôt vide',
    'errors' => [
        'not_bulk' => '« :product » n\'est pas vendu au litre (unité « Litre » à choisir sur le produit).',
        'quantity' => 'Quantité invalide.',
        'other_site' => 'Le fût :drum est dans un autre site.',
        'not_usable' => 'Le fût :drum est vide ou bloqué.',
        'insufficient' => 'Fût :drum : il reste :available, vous demandez :requested.',
        'transfer' => 'Transfert impossible (même site ou fût vide).',
        'pick_drum' => 'Choisissez le fût pour « :product ».',
    ],
];
