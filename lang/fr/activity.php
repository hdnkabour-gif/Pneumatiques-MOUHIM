<?php

return [
    'label' => 'Activité',
    'plural' => "Journal d'activité",
    'fields' => [
        'date' => 'Date',
        'user' => 'Utilisateur',
        'site' => 'Site',
        'event' => 'Action',
        'subject' => 'Élément',
        'description' => 'Description',
        'before' => 'Avant',
        'after' => 'Après',
        'ip' => 'Adresse IP',
    ],
    'events' => [
        'created' => 'Création',
        'updated' => 'Modification',
        'deleted' => 'Suppression',
        'login' => 'Connexion',
        'logout' => 'Déconnexion',
        'login_failed' => 'Échec de connexion',
        'settings_updated' => 'Paramètres modifiés',
    ],
    'system' => 'Système',
];
