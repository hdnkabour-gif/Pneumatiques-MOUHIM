# PNEU MOUHIM Manager

Application de gestion multi-sites (Dépôt Central, Magasin 01, Magasin 02) pour PNEU MOUHIM :
pneumatiques, services automobiles, stock, ventes, crédit, caisse, atelier.

- Règles du projet : [`CLAUDE.md`](CLAUDE.md)
- Spécifications : [`docs/cahier-des-charges.md`](docs/cahier-des-charges.md)
- Décisions validées : [`docs/decisions.md`](docs/decisions.md)
- Historique : [`docs/CHANGELOG.md`](docs/CHANGELOG.md)

## État d'avancement

| Phase | Contenu | État |
|---|---|---|
| 1. Fondations | Docker, auth, rôles, sites, FR/AR, journal, paramètres, sauvegardes | ✅ Déployé (sauvegarde externe R2 à activer) |
| 2. Catalogue et stock | Catalogue, pneus neufs (lots DOT), pneus d'occasion (contrôle, QR, jeux), prestations, forfaits, mouvements, transferts, réapprovisionnement, inventaire, approbations | ✅ Code et tests – en attente de validation |
| Avancé (D-48) | Référentiel véhicules et compatibilités (phases 3 / 7) | ✅ Code et tests |
| 3a. Vente au quotidien | Clients et véhicules, écran de vente unique, facture A4 (mPDF), WhatsApp, paiement espèces / carte / chèque / crédit, approbations (remise, prix, sous le coût, crédit), « Demander au dépôt », atelier saisi par le vendeur, numérotation paramétrable | ✅ Code et tests – en attente de validation |
| 3b. Documents | Devis (réservation propriétaire), BL / BT + facture récapitulative, avoirs, retours, échanges (bon d'achat), reprises | ✅ Code et tests – en attente de validation |
| 4. Argent | « Ce qui reste », comptes clients, soldes d'ouverture, encaissements, chèques, caisse et clôture, dépenses avec photo | ✅ Code et tests – en attente de validation |
| 5. Démarrage | « Aujourd'hui », objectifs, résumé du soir, PWA (push et imports Excel restants) | ✅ En partie |
| 6. Achats | Fournisseurs, commandes, réceptions partielles, dettes et paiements fournisseurs, alerte de coût | ✅ Code et tests (écran technicien facultatif D-53) |
| 7. Compléments | Équipements et maintenance ✅ ; rapports avancés et export comptable à venir | En partie |

## Versions (vérifiées le 4 novembre 2026)

| Composant | Version |
|---|---|
| PHP | 8.4 (image `dunglas/frankenphp:1-php8.4-bookworm`) |
| Laravel | 13.40 |
| Filament | 5.11 |
| Livewire | 4.4 |
| PostgreSQL | 18 (Railway, Docker local, tests CI) ; client `pg_dump` / `psql` 18 dans l'image |
| Pest | 5.3 |
| spatie/laravel-permission | 8.3 |
| spatie/laravel-activitylog | 5.1 |
| spatie/laravel-backup | 10.3 |
| mpdf/mpdf | 8.3 (PDF A4, arabe) |

Toutes les dépendances sont gratuites et open source.

## Démarrage local (Docker)

```bash
cp .env.example .env
docker compose run --rm app php artisan key:generate --show   # copier la valeur dans APP_KEY de .env
docker compose up -d --build
docker compose exec app php artisan app:create-owner
```

Application : <http://localhost:8080> (redirige vers `/admin`).

Comptes de démonstration (un par rôle, mot de passe `demo-password`), **jamais en production** :

```bash
docker compose exec app php artisan db:seed --class=DemoSeeder
```

## Tests

```bash
docker compose exec app php artisan test
```

ou sans Docker (PHP 8.4 + PostgreSQL local, base `mouhim_test`) :

```bash
vendor/bin/pest
```

Les tests tournent sur PostgreSQL (pas SQLite) pour vérifier aussi les verrous, triggers et la restauration.

## Structure

```
app/
  Actions/       logique métier (ex. SaveUserAction, RestoreDatabaseBackupAction)
  Enums/         RoleName, Permission, Locale, SiteType
  Filament/      écrans d'administration (ressources, pages)
  Models/        Site, User, Setting, Activity
  Policies/      permissions vérifiées côté serveur
  Services/      SettingsService
  Support/       Decimal (conversion exacte décimal ↔ centimes / points de base)
config/mouhim.php  valeurs par défaut des paramètres (TVA, timbre, numérotation…)
lang/fr, lang/ar   toutes les traductions de l'interface
docker/            entrypoint, php.ini
```

### Stock (phase 2) – règles techniques

- `App\Services\Stock\StockService` est le **seul** code qui modifie le stock : il écrit le mouvement
  (`stock_movements`, non modifiable), met à jour le cache `stock_levels` et les lots DOT dans la même
  transaction, avec verrouillage du produit puis du niveau (pas de stock négatif, D-45).
- CUMP au niveau de l'entreprise (D-09) ; coût inconnu = `NULL`, jamais deviné.
- `php artisan stock:verify` compare le cache et le registre (code de sortie ≠ 0 en cas d'écart).
- Les ajustements et produits endommagés passent par une **demande d'approbation** (`ApprovalService`).
- Numéros de documents : `SequenceService` (une série par type et par année, D-14).

### Référentiel véhicules et compatibilités (D-48)

- Données de référence : `docs/data/PNEU_MOUHIM_Manager_Catalogue_Operations_V1_3.xlsx` (source de vérité unique).
- Import : *Véhicules › Compatibilités pneus › Importer le classeur* (permission `compatibilities.manage`).
- Statuts : Vérifié / Non vérifié / À contrôler / Obsolète ; l'import n'attribue jamais « Vérifié ».

### Ajouter une permission (phases suivantes)

1. Ajouter un cas dans `app/Enums/Permission.php`.
2. Ajouter sa traduction dans `lang/fr/permissions.php` et `lang/ar/permissions.php`.
3. L'ajouter aux rôles concernés dans `RoleName::defaultPermissions()`.
4. Déployer : `db:seed` (lancé automatiquement au démarrage) crée la permission et la donne au propriétaire
   et aux rôles concernés, sans rétablir les permissions que le propriétaire a retirées.

## Déploiement Railway

Une seule image Docker (`Dockerfile`) et un seul service applicatif Railway, plus PostgreSQL.
Au démarrage, le service : applique les migrations, charge les données de référence, crée le
propriétaire si `OWNER_*` est défini, lance les tâches planifiées (sauvegarde quotidienne) en
arrière-plan, puis le serveur web.

Option (montée en charge) : service séparé avec la commande `/usr/local/bin/entrypoint.sh scheduler`
et `RUN_SCHEDULER=false` sur le service web.

Variables d'environnement (les deux services) :

```
APP_ENV=production
APP_DEBUG=false
APP_KEY=<php artisan key:generate --show>
APP_URL=https://<domaine>
DB_URL=${{Postgres.DATABASE_URL}}
DB_CONNECTION=pgsql
LOG_CHANNEL=stderr
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
BACKUP_S3_ENDPOINT=…  BACKUP_S3_BUCKET=…  BACKUP_S3_KEY=…  BACKUP_S3_SECRET=…
BACKUP_NOTIFY_EMAIL=<email du propriétaire>
```

Fichiers envoyés (logo, photos) : monter un **volume Railway** sur `/app/storage/app/public`
(le disque du conteneur est effacé à chaque déploiement).

Version du client PostgreSQL : `pg_dump` refuse un serveur plus récent que lui (« server version
mismatch ») et la sauvegarde échoue. L'image installe la version 18, celle du PostgreSQL de Railway ;
si le serveur passe à une version plus récente, définir la variable de build `PG_CLIENT_VERSION`.

Docker local : PostgreSQL 18 utilise le volume `pgdata18` (les données d'un ancien volume `pgdata`
en version 17 ne sont ni lues ni effacées).

Premier déploiement – compte propriétaire, au choix :

- **Console Railway** : `php artisan app:create-owner` (interactif) ;
- **sans console** : ajouter temporairement `OWNER_NAME`, `OWNER_EMAIL`, `OWNER_PASSWORD`
  (≥ 12 caractères, lettres et chiffres). Le compte est créé au démarrage s'il n'existe aucun
  propriétaire ; **supprimer ensuite ces trois variables**. Elles sont ignorées dès qu'un propriétaire existe.

## Sauvegardes et restauration

- Automatique tous les jours à 02:00 (heure du Maroc) : base de données + fichiers envoyés,
  vers un stockage S3 compatible **externe à Railway** (Cloudflare R2, Backblaze B2…).
- Conservation : tous les jours pendant 30 jours, puis dégressive (un par jour, semaine, mois, puis
  2 ans). Le nettoyage des anciennes sauvegardes n'a lieu **qu'après une sauvegarde réussie** : des nuits
  en échec ne réduisent jamais les sauvegardes existantes, et la plus récente n'est jamais supprimée.
- Contrôle de santé à 08:00. Tout échec (sauvegarde, nettoyage, sauvegarde trop ancienne) est
  signalé immédiatement aux propriétaires actifs : notification dans l'application (et sur le
  téléphone si Web Push est configuré), sans dépendre de la file d'attente. Email en plus si
  `MAIL_*` et `BACKUP_NOTIFY_EMAIL` sont configurés.
- Si une variable `BACKUP_S3_*` manque, la sauvegarde échoue avec un message qui nomme les
  variables manquantes (jamais leurs valeurs) et l'échec est signalé.
- Les tests automatisés `BackupRestoreTest` sauvegardent une base, la détruisent puis la restaurent,
  et vérifient qu'une restauration ratée ne modifie rien.

Restauration – méthode recommandée : dans une **nouvelle** base et un **nouveau** dossier de fichiers,
sans toucher à la base ni aux fichiers actuels.

```bash
php artisan app:backup-restore --into=restore_20261116          # dernière sauvegarde (ou choisir dans la liste)
php artisan app:backup-restore chemin/archive.zip --into=restore_20261116 --without-files
```

- Fichiers restaurés dans `storage/app/public/_restored/<base>` : sur le volume persistant de Railway
  (ils survivent à un redéploiement), à part des fichiers actuels, que l'application ne lit pas, et
  exclus des sauvegardes. `--files-to=` choisit un autre **nouveau** dossier ; un dossier qui est, contient
  ou se trouve dans les fichiers actuels (hors `_restored`) est refusé.
- En cas d'échec (copie des fichiers vérifiée fichier par fichier, ou chargement de la base), la nouvelle
  base et le nouveau dossier sont supprimés : il ne reste rien à moitié restauré.
- L'utilisateur PostgreSQL doit pouvoir créer une base (`CREATEDB`) ; sinon un message le dit, et il faut
  restaurer dans un service PostgreSQL séparé.
- **Bascule** (après vérification de la base restaurée) : mettre l'application en maintenance
  (`php artisan down`), copier les fichiers restaurés vers les fichiers actuels
  (`cp -a storage/app/public/_restored/<base>/. storage/app/public/`), pointer `DB_URL` vers la nouvelle
  base, redéployer, puis `php artisan up`. L'ancienne base reste intacte tant qu'on ne la supprime pas.

Remplacer la base actuelle (à éviter ; mettre l'application en maintenance avec `php artisan down`) :

```bash
php artisan app:backup-restore --replace-current
```

Ce qui est garanti, étape par étape :

1. Avant toute modification : archive contrôlée (lisible, mot de passe, dump complet) ; fichiers copiés
   à côté de leur destination puis vérifiés (liste et taille de chaque fichier), et aucun obstacle pour
   les mettre en place. Un échec ici ne change **ni la base ni les fichiers**.
2. Suppression de l'ancien schéma et chargement de la base dans **une seule transaction** : en cas
   d'erreur, la base et les fichiers restent comme avant.
3. Mise en place des fichiers vérifiés (déplacements sur le même disque ; les fichiers présents sont
   remplacés, aucun n'est supprimé). Si cette dernière étape échoue, **la base a déjà été restaurée** :
   le message le dit et indique le dossier où la copie complète des fichiers est conservée pour finir
   à la main. Base et fichiers ne forment donc pas une seule opération atomique.

- En production, il faut taper le nom de la base pour confirmer (`--force` ne suffit pas).
- Les paramètres du serveur viennent de la connexion active (`DB_URL` compris).

Sauvegarde manuelle : `php artisan backup:run`.

## Sécurité

- Aucun compte par défaut ; le propriétaire est créé par `app:create-owner` (mot de passe ≥ 12 caractères).
- Connexion limitée à 5 tentatives par minute ; comptes désactivables (jamais supprimés).
- Double authentification obligatoire pour les propriétaires (D-47). Téléphone perdu : se connecter avec un
  code de secours, puis reconfigurer depuis « Profil ».
- Toutes les pages vérifient les permissions côté serveur (Policies), testé par URL directe.
- Journal d'activité non modifiable (protégé par trigger PostgreSQL).
- `.env` et secrets jamais commités.
