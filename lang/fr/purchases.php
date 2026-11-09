<?php

return [
    'suppliers' => 'Fournisseurs', 'supplier' => 'fournisseur',
    'orders' => 'Commandes fournisseurs', 'order' => 'commande',
    'fields' => [
        'name' => 'Nom', 'phone' => 'Téléphone', 'email' => 'Email', 'ice' => 'ICE', 'city' => 'Ville', 'address' => 'Adresse',
        'payment_terms_days' => 'Délai de paiement (jours)', 'opening_balance' => 'Solde d\'ouverture (dû)', 'notes' => 'Remarques',
        'balance' => 'Nous devons', 'number' => 'N°', 'status' => 'État', 'site' => 'Site de réception', 'expected_at' => 'Livraison prévue',
        'lines' => 'Articles', 'product' => 'Produit', 'quantity' => 'Qté', 'received' => 'Reçu', 'unit_cost' => 'Coût unitaire HT',
        'invoice_ref' => 'N° facture fournisseur', 'dot' => 'DOT (ex. 2425)', 'received_now' => 'Déjà reçu (réception immédiate)',
        'amount' => 'Montant', 'method' => 'Moyen', 'reference' => 'Référence', 'date' => 'Date', 'total' => 'Total TTC',
    ],
    'status' => ['ordered' => 'Commandée', 'partial' => 'Reçue en partie', 'received' => 'Reçue', 'cancelled' => 'Annulée'],
    'actions' => ['receive' => 'Réceptionner', 'pay' => 'Payer', 'compare' => 'Comparer les prix'],
    'receipts' => 'Réceptions', 'payments' => 'Paiements',
    'cost_rise' => 'Hausse du coût de « :product » : :from → :to',
    'suggested_price' => 'Prix de vente suggéré : :price',
    'no_history' => 'Aucun achat enregistré.',
    'errors' => [
        'no_lines' => 'Ajoutez au moins un article avec une quantité.',
        'order_closed' => 'Cette commande est déjà reçue ou annulée.',
    ],
];
