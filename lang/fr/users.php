<?php

return [
    'label' => 'Utilisateur',
    'plural' => 'Utilisateurs',
    'fields' => [
        'name' => 'Nom complet',
        'email' => 'Email',
        'phone' => 'Téléphone',
        'password' => 'Mot de passe',
        'password_help' => 'Laisser vide pour conserver le mot de passe actuel.',
        'role' => 'Rôle',
        'sites' => 'Sites affectés',
        'default_site' => 'Site par défaut',
        'locale' => 'Langue',
        'is_active' => 'Compte actif',
        'last_login_at' => 'Dernière connexion',
    ],
    'errors' => [
        'default_site_not_assigned' => 'Le site par défaut doit faire partie des sites affectés.',
        'cannot_disable_self' => 'Vous ne pouvez pas désactiver votre propre compte.',
        'last_owner' => 'Il doit rester au moins un propriétaire actif.',
    ],
    'commands' => [
        'owner_created' => 'Compte propriétaire créé : :email',
        'owner_exists' => 'Un propriétaire existe déjà. Utilisez --force pour en créer un autre.',
        'owner_env_ignored' => 'Un propriétaire existe déjà : les variables OWNER_* sont ignorées. Supprimez-les dans Railway.',
    ],
];
