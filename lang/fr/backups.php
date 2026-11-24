<?php

return [
    'alert' => [
        'title' => 'Sauvegarde : échec',
        'body' => 'La tâche « :task » a échoué. Les données ne sont peut-être plus sauvegardées : prévenez la personne chargée de l\'application.',
    ],
    'tasks' => [
        'backup:run' => 'sauvegarde quotidienne',
        'backup:clean' => 'nettoyage des anciennes sauvegardes',
        'backup:monitor' => 'contrôle des sauvegardes',
        'app:secure-receipts' => 'mise à l\'abri des justificatifs de dépenses (justificatif manquant ou copie publique non supprimée)',
    ],
];
