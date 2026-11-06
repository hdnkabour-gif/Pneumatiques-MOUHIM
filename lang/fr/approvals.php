<?php

return [
    'label' => 'Demande d\'approbation',
    'plural' => 'Approbations',
    'fields' => [
        'type' => 'Type',
        'status' => 'Statut',
        'site' => 'Site',
        'requested_by' => 'Demandé par',
        'reason' => 'Motif',
        'summary' => 'Demande',
        'decided_by' => 'Décidé par',
        'decided_at' => 'Décidé le',
        'decided_via' => 'Mode',
        'comment' => 'Commentaire',
        'created_at' => 'Demandé le',
        'pin' => 'Code PIN du propriétaire',
        'approval_pin' => 'Code PIN d\'approbation',
        'approval_pin_help' => '4 à 8 chiffres. Permet d\'approuver sur l\'écran d\'un employé. Laisser vide pour ne pas le changer.',
    ],
    'via' => ['app' => 'Application', 'pin' => 'Code PIN', 'self' => 'Auto-approuvé'],
    'actions' => [
        'approve' => 'Approuver',
        'reject' => 'Refuser',
        'approve_with_pin' => 'Approuver avec PIN',
        'open' => 'Ouvrir',
    ],
    'notifications' => [
        'new' => 'Nouvelle demande : :type',
        'granted' => 'Votre demande a été approuvée',
        'rejected' => 'Votre demande a été refusée',
    ],
    'messages' => [
        'approved' => 'Demande approuvée et opération exécutée.',
        'rejected' => 'Demande refusée.',
    ],
    'summaries' => [
        'credit_note' => 'Avoir sur :invoice : :total (:refund)',
        'sale' => ':type – vente :total, client :customer (remise max :discount %, crédit :credit)',
        'stock_adjustment' => 'Ajustement :delta de « :product » à :site',
        'damaged_stock' => 'Produit endommagé :delta de « :product » à :site',
    ],
    'errors' => [
        'reason_required' => 'Indiquez un motif.',
        'already_decided' => 'Cette demande a déjà été traitée.',
        'pin_invalid' => 'Code PIN incorrect.',
        'pin_locked' => 'Trop d\'essais : réessayez dans une minute.',
    ],
];
