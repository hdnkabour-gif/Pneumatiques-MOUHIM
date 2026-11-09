<?php

return [
    'title' => 'Aujourd\'hui',
    'sales_today' => 'Ventes du jour',
    'sales_count' => 'vente(s)',
    'vs_yesterday' => 'vs hier',
    'cash' => 'Espèces', 'card' => 'Carte', 'cheque' => 'Chèques', 'credit' => 'À crédit', 'expenses' => 'Dépenses',
    'margin' => 'Marge', 'margin_incomplete' => 'Incomplète (coût manquant)',
    'month' => 'Ce mois',
    'live' => 'En direct',
    'set_targets' => 'Objectifs du mois',
    'events' => ['sale_confirmed' => 'Vente', 'quote_issued' => 'Devis', 'credit_note' => 'Avoir', 'cash_closed' => 'Caisse clôturée',
        'transfer_sent' => 'Transfert envoyé', 'transfer_received' => 'Transfert reçu', 'payment_received' => 'Encaissement', 'expense' => 'Dépense'],
    'alerts' => [
        'approvals' => ':count demande(s) attendent votre accord',
        'cheques' => ':count chèque(s) à échéance : :amount',
        'late' => ':count client(s) doivent depuis plus de 30 jours',
    ],
    'summary' => [
        'title' => 'Résumé du :date',
        'line' => ':site : ventes :sales, espèces :cash, carte :card, chèques :cheque, crédit :credit, dépenses :expenses',
        'total' => 'Total : :sales (:count ventes)',
    ],
];
