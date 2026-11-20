<?php

return [
    'receive' => 'Encaisser',
    'receive_help' => 'Le montant est affecté automatiquement aux dettes les plus anciennes.',
    'opening' => 'Solde d\'ouverture',
    'opening_help' => 'Dette existante avant l\'application (cahier, Excel), à sa date.',
    'as_of' => 'Date',
    'note' => 'Remarque',
    'amount' => 'Montant',
    'method' => 'Moyen',
    'done' => 'Encaissement enregistré.',
    'payments' => 'Encaissements',
    'cheque_status' => ['received' => 'Reçu', 'deposited' => 'Déposé en banque', 'cleared' => 'Encaissé', 'rejected' => 'Rejeté'],
    'actions' => ['deposited' => 'Déposé', 'cleared' => 'Encaissé', 'rejected' => 'Rejeté'],
    'reason' => 'Motif du rejet',
    'errors' => [
        'amount' => 'Montant invalide.',
        'more_than_due' => 'Le client doit :due : le montant ne peut pas être supérieur.',
        'idempotency_key' => 'Référence de l\'opération invalide. Rouvrez le formulaire et réessayez.',
        'idempotency_conflict' => 'Cette opération a déjà été envoyée avec d\'autres valeurs. Rouvrez le formulaire pour un nouvel encaissement.',
        'cheque_status' => 'Changement impossible pour ce chèque.',
    ],
];
