<?php

return [
    'label' => 'équipement',
    'plural' => 'Équipements',
    'history' => 'Historique (pannes, réparations, entretiens)',
    'every_help' => 'Entretien préventif tous les X jours : la date suivante est calculée seule.',
    'broken_alert' => 'Panne signalée : :name (:site)',
    'fields' => [
        'code' => 'N°', 'name' => 'Nom', 'category' => 'Type', 'site' => 'Site', 'status' => 'État', 'brand' => 'Marque', 'model' => 'Modèle',
        'serial' => 'N° de série', 'purchased_at' => 'Acheté le', 'cost' => 'Coût', 'warranty_until' => 'Garantie jusqu\'au',
        'every_days' => 'Entretien tous les (jours)', 'next' => 'Prochain entretien', 'photo' => 'Photo', 'notes' => 'Remarques',
        'total_cost' => 'Coût total d\'entretien', 'date' => 'Date', 'type' => 'Type', 'description' => 'Travaux / problème',
        'performed_by' => 'Fait par (technicien, société)', 'what' => 'Quel problème ?',
    ],
    'status' => ['in_service' => 'En service', 'broken' => 'En panne', 'retired' => 'Hors service'],
    'events' => ['breakdown' => 'Panne', 'repair' => 'Réparation', 'maintenance' => 'Entretien'],
    'actions' => ['breakdown' => 'Signaler une panne', 'service' => 'Réparation / entretien'],
    'categories' => [
        'tyre_changer' => 'Démonte-pneu', 'balancer' => 'Équilibreuse', 'alignment' => 'Banc de parallélisme', 'compressor' => 'Compresseur',
        'nitrogen' => 'Générateur d\'azote', 'lift' => 'Pont élévateur', 'jack' => 'Cric', 'impact_wrench' => 'Clé à choc',
        'oil_equipment' => 'Matériel de vidange', 'headlight_tester' => 'Réglophare', 'other' => 'Autre',
    ],
    'alert_due' => ':count équipement(s) à entretenir ou en panne',
    'errors' => ['event' => 'Indiquez le type et les travaux faits.'],
];
