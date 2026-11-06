<?php

return [
    'self_approved' => 'Accordé par le propriétaire lui-même',
    'lines' => [
        'used_tyre' => 'Pneu d\'occasion :size (:code)',
    ],
    'errors' => [
        'approval_required' => 'Cette vente a besoin de l\'accord du propriétaire : :types.',
        'approval_outdated' => 'La vente a changé depuis la demande : une nouvelle demande est nécessaire.',
        'quantity' => 'La quantité doit être d\'au moins 1.',
        'product_unavailable' => 'Ce produit n\'est plus disponible à la vente.',
        'service_not_offered' => 'La prestation « :service » n\'est pas proposée à :site.',
        'used_tyre_unavailable' => 'Le pneu d\'occasion :code n\'est pas vendable ici (non contrôlé, refusé, déjà vendu ou dans un autre site).',
        'empty' => 'Ajoutez au moins un article.',
        'not_draft' => 'Cette vente est déjà validée : elle ne peut plus être modifiée. Pour corriger, faites un avoir.',
        'overpaid' => 'Le montant payé dépasse le total. Saisissez seulement le montant de la facture (la monnaie rendue n\'est pas enregistrée).',
        'credit_needs_customer' => 'Pour laisser un reste à crédit, choisissez ou créez le client.',
        'po_required' => 'Ce client exige un numéro de bon de commande.',
        'payment_method' => 'Moyen de paiement inconnu.',
        'cheque_details' => 'Pour un chèque, indiquez le numéro et la banque.',
    ],
];
