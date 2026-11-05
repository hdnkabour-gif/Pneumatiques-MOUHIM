<?php

return [
    'label' => 'Inventaire',
    'plural' => 'Inventaires',
    'fields' => [
        'number' => 'N°',
        'site' => 'Site',
        'category' => 'Catégorie (vide = complet)',
        'status' => 'Statut',
        'product' => 'Produit',
        'expected' => 'Attendu',
        'counted' => 'Compté',
        'difference' => 'Écart',
        'notes' => 'Notes',
        'progress' => 'Comptés',
        'created_at' => 'Créé le',
    ],
    'actions' => [
        'count' => 'Compter',
        'save' => 'Enregistrer les quantités',
        'submit' => 'Envoyer pour validation',
        'approve' => 'Valider et ajuster le stock',
        'cancel' => 'Annuler l\'inventaire',
    ],
    'messages' => [
        'saved' => 'Quantités enregistrées.',
        'submitted' => 'Inventaire envoyé pour validation.',
        'approved' => 'Inventaire validé : le stock a été ajusté.',
        'cancelled' => 'Inventaire annulé.',
    ],
    'errors' => [
        'negative' => 'Une quantité comptée ne peut pas être négative.',
        'nothing_counted' => 'Aucune quantité n\'a été comptée.',
        'wrong_status' => 'Cette action n\'est pas possible à ce stade de l\'inventaire.',
    ],
];
