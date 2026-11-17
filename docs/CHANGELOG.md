# Changelog

## [Préparation à la mise en service — contrôle sans suppression] – 2026-11-17

### Ajouté
- L'écran de numérotation permet de régler le format et le remplissage pour les futurs documents,
  avec aperçu et validation, sans renuméroter les factures existantes (D-58).
- `php artisan app:go-live-audit` affiche uniquement des comptages des données commerciales et
  référentielles et la configuration de numérotation ; aucune suppression, aucun secret affiché.
  Une purge des données de démonstration nécessite un processus distinct et vérifié.

## [Images envoyées] – 2026-11-17

### Corrigé
- Les images envoyées (logo, photos des pneus d'occasion, des dépenses, des équipements) ne
  s'affichaient pas : l'image Docker ne contient pas le lien `public/storage`, et l'adresse générée
  répondait 403 (vérifié avec un vrai envoi de logo par le formulaire). Le service web crée maintenant
  ce lien à chaque démarrage.

## [Données de démonstration Staging] – 2026-11-17

### Ajouté
- `php artisan app:staging-demo-data` : journée de travail fictive complète sur les trois sites
  (achats, réceptions, transferts, ventes espèces / carte / crédit, factures, chèque, paiement
  fournisseur, photos, clôture de caisse), refusée hors du projet Railway de Staging (identifiant du
  projet et nom de l'environnement fixés par Railway, pas `APP_ENV`).

## [Fiabilité des sauvegardes – audit de sécurité, phase A] – 2026-11-16

### Corrigé
- Sauvegarde quotidienne en échec chaque nuit (code 1) : le client PostgreSQL de l'image (17) refusait
  le serveur Railway (18). L'image, la CI et Docker local passent à PostgreSQL 18.
- Variables `BACKUP_S3_*` absentes : l'erreur n'était pas signalée (TypeError non interceptée par
  spatie/laravel-backup). Elle devient un échec clair, qui nomme les variables manquantes.
- Restauration : avec `DB_URL` seul (configuration Railway), l'ancienne commande supprimait le schéma
  de la vraie base puis restaurait sur un autre serveur, laissant la base vide. Les paramètres viennent
  maintenant de la connexion active.

### Modifié
- `app:backup-restore` demande un mode explicite :
  - `--into=<nouvelle_base>` (recommandé) : nouvelle base ; fichiers copiés pour vérification dans un
    dossier **privé** (`storage/app/private/restored/<base>`, hors de l'arborescence web publique) ; la
    base et les fichiers actuels ne sont pas touchés ; en cas d'échec, la base et le dossier créés sont
    supprimés, chacun indépendamment, et ce qui reste est signalé sans masquer l'erreur d'origine.
  - `--replace-current` : archive contrôlée et absence d'obstacle vérifiée **avant** toute modification,
    base remplacée en une seule transaction, puis fichiers mis en place. Base et fichiers ne forment pas
    une opération atomique : si la mise en place des fichiers échoue, la base a déjà été restaurée et le
    message indique combien de fichiers sont en place et la commande qui termine à partir de l'archive.
    En production, nom de la base à taper.
  - `--files-only` (nouveau) : remet en place les fichiers d'une archive sans toucher à la base ;
    répétable ; termine une restauration partielle ou sert à la bascule. Confirmation en production.
  - Chaque fichier est copié à côté de sa destination, contrôlé par empreinte SHA-256, puis renommé
    par-dessus en une opération : jamais de fichier actuel remplacé par une copie incomplète.
- Les anciennes sauvegardes ne sont nettoyées qu'après une sauvegarde réussie (plus de nettoyage à 01:30
  les nuits où la sauvegarde échoue).

### Ajouté
- Alerte aux propriétaires actifs quand une tâche de sauvegarde échoue : notification enregistrée
  immédiatement dans l'application (sans file d'attente) et envoi Web Push si configuré. Limites : la
  réception sur le téléphone n'est pas prouvée par les tests, et aucune alerte n'est possible si
  l'application, la base ou le planificateur sont arrêtés (surveillance externe à décider).
- Tests : `DB_URL`, archive endommagée ou tronquée, dump en échec en cours de route, archive chiffrée,
  pannes injectées à chaque étape (copie, copie altérée, obstacle, échec au 2e fichier après la mise en
  place du 1er, base, suppression de la base), copies de restauration hors de l'arborescence publique,
  droit `CREATEDB`, conservation des sauvegardes ; journée de travail réaliste sur les trois sites
  restaurée à l'identique : contenu de chaque table, valeurs des séquences, définitions exactes des
  contraintes, déclencheurs, index et fonctions, factures PDF complètes.

### Reste à prouver (avant production)
- Sauvegarde et restauration réelles avec Cloudflare R2 sur un environnement Railway de test (droits du
  bucket, image construite, volume, droit `CREATEDB`).
- Valeur réelle de `QUEUE_CONNECTION` sur Railway (aucun worker séparé n'y tourne) : si c'est `database`,
  les autres notifications (approbations…), qui passent par la file d'attente, ne seraient jamais affichées.

## [Phase 2 – Catalogue et stock] – 2026-11-05

### Ajouté
- Catalogue : catégories hiérarchiques (FR/AR, préfixe SKU, marge par défaut vide), marques, produits avec
  SKU automatique (`PN-000123`), 3 niveaux de prix, prix d'achat de référence, CUMP, prix suggéré
  (coût + marge, jamais calculé si une donnée manque), références alternatives, seuils minimum par site.
- Caractéristiques par type : pneus (dimension en champs séparés, indices, saison, XL, Run Flat…), huiles
  (viscosité, normes, contenance, vente au bidon), batteries, filtres, éclairage (culot).
- Recherche tolérante : `205 55 16`, `2055516`, `205/55R16`, `205/55 R16 91V`, SKU, référence, `5W30`, `H7`.
- Prestations (prix fixe ou selon le diamètre de jante, consommables automatiques, sites) et forfaits
  (contrôle que la somme des lignes égale le prix du forfait).
- Stock : registre des mouvements non modifiable, niveaux par site, réservé / disponible, valeur au CUMP
  (propriétaire uniquement), sous le seuil, en rupture, produits dormants, fiche de stock par produit,
  lots DOT avec sortie du plus ancien en premier et alerte « pneus anciens » (36 mois).
- Réception simple / stock d'ouverture (en attendant les achats, phase 6).
- Transferts : en transit → reçu / annulé, écarts avec motif obligatoire, lots DOT conservés, bon de
  transfert imprimable, numérotation `TR-2026-000001`.
- Demandes de réapprovisionnement avec suggestion automatique et transformation en transfert.
- Inventaire : comptage sur téléphone / tablette (comptage à l'aveugle), validation par le propriétaire,
  ajustements automatiques.
- Module d'approbation : demande avec motif, notification au propriétaire (cloche), approbation / refus,
  approbation sur place par code PIN (5 essais / minute), journalisation.
- Pneus d'occasion : fiche individuelle, contrôle de sécurité en 9 points (un seul point dangereux =
  non vendable), photos, étiquette QR imprimable, mise au rebut, jeux assortis de 2 ou 4 (±1 mm).
- `php artisan stock:verify`.
- 199 tests, dont le scénario de stock multi-sites du §21.

### Corrigé
- Déconnexion intempestive lors des requêtes Livewire en arrière-plan (phase 1).
- Affichage des montants ≥ 1 000 MAD.

## [Non publié]

### Numéro de facture « 219/26 » (D-58)
- Variable `{YY}` (année sur deux chiffres), préfixe de facture vide autorisé, format vérifié, préfixes en double refusés ; appliqué à l'installation en service.

### Simplification du menu (D-57)
- Les pages secondaires sont regroupées derrière un bouton « Plus » sur leur page principale ; le menu du propriétaire passe d'environ 24 à 14 entrées, celui du vendeur à 10, en trois groupes (Ventes, Stock, Paramètres).

### Ajouté – Rapports (10 novembre 2026)
- Page « Rapports » (propriétaire, responsables sur leurs sites) : période (aujourd'hui, semaine, mois, mois dernier,
  année ou dates libres) et site ; chiffre d'affaires, nombre de ventes, panier moyen, marge (⚠ si un coût manque) ;
  par site, vendeur, catégorie, marque ; 10 produits les plus vendus ; balance âgée des clients (0–30, 31–60, 61–90,
  > 90 jours) ; valeur du stock par site (coûts réservés au propriétaire) ; export Excel.

### Ajouté – Notifications sur le téléphone et papier à en-tête (10 novembre 2026)
- Notifications push (Web Push) : bouton « Notifications sur ce téléphone » sur la page « Aujourd'hui » ; chaque
  notification de l'application (approbation, écart de caisse, résumé du soir, panne, hausse de coût…) arrive aussi
  sur le téléphone. Clés générées par `php artisan push:keys`, à mettre dans les variables Railway (`VAPID_PUBLIC_KEY`,
  `VAPID_PRIVATE_KEY`) ; sans clés, rien n'est envoyé. Sur iPhone : ajouter d'abord l'application à l'écran d'accueil.
- Documents A4 sur le papier de la société : « PNEUMATIQUE MOUHIM » + « PM » (ou le logo téléversé), coins jaunes,
  marques en pied de page, siège, RC, I.F, T.P, CNSS, ICE et téléphones sur chaque page (renseignés d'après la photo
  du papier à en-tête, seulement si les paramètres étaient vides).
- Modèles d'import avec une feuille « Explications » (chaque colonne et un exemple).

### Ajouté – Phase 5 (imports de démarrage)
- « Imports de démarrage » (propriétaire) : modèles Excel à télécharger pour les clients (avec dette existante datée,
  plafond de crédit et immatriculation), les produits (nom ou dimension pneu, catégorie, marque, prix, coût, unité)
  et le stock d'ouverture par site (avec DOT). Chaque ligne est vérifiée ; une seule erreur = rien n'est importé et
  la liste des erreurs ligne par ligne s'affiche ; jamais d'écrasement (clients par téléphone / ICE, produits par SKU).

### Ajouté – Phase 7 (export comptable)
- « Export comptable » (propriétaire) : un fichier Excel par mois — ventes et avoirs (HT, TVA, TTC, ICE client),
  TVA collectée par taux, achats fournisseurs, encaissements avec l'état des chèques, dépenses. Format à valider
  avec le comptable (D-30).

### Ajouté – Phase 7 (équipements et maintenance)
- Registre des équipements par site (démonte-pneu, équilibreuse, banc, compresseur, azote, pont, cric, clé à choc,
  vidange, réglophare…), marque, modèle, n° de série, achat, garantie, photo.
- Entretien préventif tous les X jours (date suivante calculée), signalement de panne par le personnel du site
  (alerte aux responsables), réparation / entretien avec coût et intervenant, coût total par équipement.
- Alerte « équipements à entretenir ou en panne » sur la page « Aujourd'hui ».

### Ajouté – Phase 6 (achats)
- Fournisseurs (solde d'ouverture par le propriétaire) avec « Nous devons » ; paiement fournisseur (propriétaire).
- Commandes fournisseurs (`BC-…`), réception totale ou partielle au site choisi (dépôt par défaut), coût HT,
  DOT des pneus (ex. 2425), ou « Déjà reçu » pour une réception directe ; le stock entre au coût (CUMP) et la
  dette fournisseur (TTC) augmente.
- Hausse du coût d'achat de plus de 5 % (paramétrable) : alerte au propriétaire avec le prix de vente suggéré ;
  comparaison du dernier coût par fournisseur.
- Permissions `purchases.manage` (magasinier) et `suppliers.pay` (propriétaire).

### Ajouté – Phase 5 (démarrage, propriétaire)
- Page « Aujourd'hui » (téléphone d'abord) : alertes à traiter (5 max), ventes du jour et comparaison avec la veille,
  encaissements par moyen, crédit, dépenses, marge (incomplète si un coût manque, visible avec la permission coûts),
  ventes du mois par site avec objectif et barre de progression, fil « en direct ». Rafraîchie chaque minute.
- Objectifs mensuels par site fixés par le propriétaire.
- Résumé du soir (20 h, `EVENING_SUMMARY_AT`) : notification dans l'application + email au propriétaire.
- Application installable sur l'écran d'accueil du téléphone (PWA : manifeste, icônes, service worker sans cache).
- Référence des indices de charge et de vitesse (ETRTO / ISO) affichée sur l'écran de vente (ex. 91V = 615 kg · 240 km/h).
- Reste à faire en phase 5 : notifications push hors application (clés VAPID à créer) et imports Excel de démarrage.

### Ajouté – Phase 4 (argent)
- Page « Ce qui reste » (propriétaire et responsables, téléphone) : clients qui doivent avec ancienneté et relance
  WhatsApp, chèques à encaisser, devis en attente, véhicules à l'atelier, transferts et demandes, approbations.
- Comptes clients : solde d'ouverture saisi par le propriétaire seul (D-23) ; « Encaisser » depuis la fiche client,
  montant affecté automatiquement aux dettes les plus anciennes (D-20).
- Chèques : reçu → déposé → encaissé, ou rejeté avec motif ; un rejet rétablit la dette sans effacer l'historique (D-22).
- Caisse par site : fond de caisse, espèces reçues, rendus, dépenses en espèces, montant attendu ; clôture avec
  montant compté et écart (alerte au propriétaire au-delà de 50 MAD, paramétrable) ; journée verrouillée après
  clôture, réouverture par le propriétaire avec motif (D-29) ; dépenses avec photo du ticket.
- Permissions `payments.receive`, `cheques.manage`, `expenses.create`, `cash.close`.

### Ajouté – Huile au litre depuis des fûts (D-06 révisée, 9 novembre 2026)
- Fûts d'huile : réception (capacité, quantité réellement reçue, prix, frais, fournisseur, facture), coût du litre,
  reste et consommé, valeur restante (propriétaire), alerte « bientôt vide », correction de jauge motivée,
  transfert vers un autre site, blocage ; registre de mouvements non modifiable.
- Écran de vente : pour une huile au litre, les fûts du site s'affichent avec leur reste ; litres au clavier
  (2,5 / 4,25) ou boutons 1 L / 4 L / 5 L ; prix au litre, remise, coût figé sur la ligne ; refus au-delà du reste.
- Bidons fermés inchangés et séparés ; retour client remis dans le fût ; `stock:verify` contrôle les fûts.

### Corrections après audit externe (9 novembre 2026)
- Marge et contrôle « vente sous le coût » : le coût des consommables des prestations (masses, valves…)
  et le coût des pneus d'occasion sont maintenant comptés ; une reprise n'est plus comptée comme une perte de marge.
- Un ajustement approuvé ne laisse jamais plus de stock réservé que de stock réel.
- Un pneu neuf retourné revient avec son DOT d'origine (lots de la vente), même en plusieurs retours.
- Reprise d'un pneu : le DOT (ex. 2423) est enregistré sur la fiche du pneu d'occasion.
- `stock:verify` contrôle aussi chaque pneu d'occasion et les réservations.
- Restauration d'une sauvegarde : les fichiers (logo, photos) reviennent avec la base ; test de bout en bout.
- Ajouts du 8 novembre vérifiés et conservés (catalogue véhicules chinois au Maroc, catalogues de référence
  pneus / huiles, audit du classeur) ; l'import du classeur ne peut plus bloquer le démarrage en production,
  et ces pages de référence sont retirées du menu du vendeur.

### Revue complète de la plateforme (6 novembre 2026)
- Parcours automatique de toutes les pages pour chaque rôle (propriétaire, responsable, vendeurs FR/AR, magasinier,
  technicien) sur un écran de téléphone : 189 pages, aucun débordement horizontal, aucune erreur JavaScript.
- Corrigé : la fiche d'un transfert provoquait une erreur serveur (chargement des lignes) ; test de non-régression ajouté.
- Simplifié : les pages de configuration (catégories, marques, prestations, forfaits, référentiel véhicules,
  sources, mouvements, sites) n'apparaissent dans le menu que pour ceux qui les gèrent. Menu du vendeur :
  Nouvelle vente, Documents, Clients, Atelier, Produits, Stock, Pneus d'occasion, Réapprovisionnement, Recherche par véhicule.

### Ajouté – Phase 3b (documents)
- Devis depuis l'écran de vente (bouton Facture / Devis / Bon), validité, PDF et WhatsApp, « Transformer en vente »
  en un clic (prix du devis conservé, jamais au-dessus du prix du jour), « Refusé », expiration automatique ;
  réservation du stock réservée au propriétaire (D-13).
- Bons de livraison (BL) et bons de travail (BT) pour les clients société, signature sur le PDF ;
  facture récapitulative depuis la fiche client (stock et crédit comptés une seule fois).
- Retour / avoir depuis la facture : quantités par ligne, remise en stock ou « endommagé », remboursement en espèces,
  déduction du crédit ou bon d'achat utilisable comme paiement (échange) ; accord du propriétaire pour les vendeurs.
- Reprises pneu / batterie déduites de la facture, valeur plafonnée (D-52), pneus créés « en attente de contrôle ».
- Page « Documents » unique avec onglets Factures / Devis / Bons / Avoirs / Brouillons.
- Crédit : seul le propriétaire fixe le plafond et décide qui achète à crédit (D-54 validée).

### Ajouté – Phase 3a (vente au quotidien)
- Clients (particuliers / sociétés, ICE, IF, RC, bon de commande exigé) et leurs véhicules ; recherche unique
  par téléphone (06…, +212…), immatriculation (`12345-أ-6`, `12345 أ 6`), nom ou ICE, aussi dans la recherche globale.
- Écran « Nouvelle vente » sur un seul écran, utilisable au téléphone : client rapide (téléphone + nom),
  recherche produit par dimension / référence / code PO, stock du site et des autres sites, produits des
  compatibilités vérifiées du véhicule, boutons prestations et forfaits, quantités ×2 / ×4, remise en %,
  espèces par défaut, carte, chèque (numéro, banque, tireur, échéance), plusieurs moyens, reste à crédit,
  monnaie à rendre, techniciens en gros boutons, « Demander au dépôt ».
- Validation dans une transaction : sortie de stock (pneus neufs par lot, occasions contrôlées uniquement,
  consommables des prestations), numéro de facture à la validation, paiements, ordre de travail si prestations,
  coût figé par ligne (marge « incomplète » si un coût manque).
- Approbations : remise au-delà du plafond du rôle, prix modifié, vente sous le coût, crédit au-delà du plafond
  (D-54, D-55) ; code du propriétaire sur place ou demande sur son téléphone ; brouillon repris ensuite.
- Facture validée figée : ni modification ni suppression, y compris en SQL (déclencheurs PostgreSQL).
- Facture A4 PDF (mPDF) : en-tête société, ICE client, détail HT / TVA par taux / TTC, montant en toutes lettres.
- WhatsApp : lien `wa.me` avec la facture (lien signé valable 30 jours) ; « véhicule prêt » depuis l'atelier.
- Page « Atelier » : ordres de travail par statut, techniciens, Démarrer / Pause / Terminé / Livré, observations,
  prochaine vidange sur la fiche véhicule (D-53).
- Numérotation : préfixe de chaque document et prochain numéro de l'année pour continuer la numérotation papier,
  sans retour en arrière ni doublon (D-49) ; remises maximales paramétrables.
- Permissions `customers.view`, `customers.manage`, `sales.create`, `sales.view`, `work_orders.manage`
  (vendeur et responsable de site) ; technicien et magasinier sans accès aux ventes, même par URL.

### Ajouté
- Référentiel véhicules (marques, modèles, générations, motorisations) et compatibilités pneus / huiles
  avec source, date, auteur et statut (D-48) ; seules les compatibilités « Vérifié » sont présentées
  comme compatibles ; validation réservée à la permission `compatibilities.verify` et journalisée.
- Import du classeur de référence (`docs/data/…V1_3.xlsx`) : idempotent, aucun import en « Vérifié »,
  jamais d'écrasement d'une compatibilité vérifiée dans l'application.
- Page « Recherche par véhicule » : compatibilités vérifiées et produits correspondants avec le stock par site.
- 57 marques de pneus et de lubrifiants issues du classeur de référence V1.2 (noms seuls, aucun produit créé).
- Double authentification obligatoire pour le propriétaire (application Google / Microsoft Authenticator,
  codes de secours), configurée depuis la page « Profil » (D-47). Indisponible pour les autres rôles.
- Page « Profil » : chaque utilisateur peut changer son nom, son email et son mot de passe.

### Ajouté
- Création du premier propriétaire depuis les variables `OWNER_*` au démarrage du conteneur
  (`app:create-owner --from-env`), pour les hébergements sans console disponible. Sans effet si un
  propriétaire existe déjà ; un mot de passe trop faible est refusé sans bloquer le démarrage.

### Modifié
- Les tâches planifiées tournent dans le conteneur web (plus besoin d'un service `scheduler` séparé
  sur Railway) ; désactivable avec `RUN_SCHEDULER=false`.

## [Phase 1 – Fondations] – 2026-11-04

### Ajouté
- Projet Laravel 13 + Filament 5 + Livewire 4 + PostgreSQL, image Docker unique (FrankenPHP) pour local et Railway.
- Sites : Dépôt Central (DEP), Magasin 01 (M01), Magasin 02 (M02) ; ajout de sites possible ; jamais supprimés.
- Utilisateurs : rôle, sites affectés, site par défaut, langue, activation ; jamais supprimés.
- Rôles par défaut : Propriétaire, Responsable de site, Vendeur / Caissier, Magasinier, Technicien ;
  permissions modifiables par le propriétaire ; rôle Propriétaire verrouillé.
- Visibilité par site appliquée côté serveur (listes et accès direct par URL).
- Interface en français et en arabe (RTL), choix de la langue par utilisateur et sur la page de connexion.
- Journal d'activité : qui, quoi, quand, site, IP, avant / après ; filtrable ; non modifiable.
- Paramètres société : identité, ICE / IF / RC / Patente / CNSS, banque, TVA (20 %), droit de timbre,
  traitement TVA occasion / reprises, format de numérotation, mentions légales (D-19, D-30).
- Sécurité : aucun compte par défaut, commande `app:create-owner`, limitation des tentatives de connexion.
- Sauvegarde quotidienne vers stockage S3 compatible externe, commande de restauration `app:backup-restore`.
- `DemoSeeder` (un compte par rôle), refusé en production.
- 127 tests Pest (connexion par rôle, accès par URL, visibilité par site, FR/AR, journal, paramètres,
  sauvegarde + restauration) et intégration continue GitHub Actions.
