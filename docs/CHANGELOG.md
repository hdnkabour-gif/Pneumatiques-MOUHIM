# Changelog

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
