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
| 1. Fondations | Docker, auth, rôles, sites, FR/AR, journal, paramètres, sauvegardes | ✅ Code et tests (déploiement Railway à faire) |
| 2. Catalogue et stock | — | À venir |
| 3 → 7 | — | À venir |

## Versions (vérifiées le 4 novembre 2026)

| Composant | Version |
|---|---|
| PHP | 8.4 (image `dunglas/frankenphp:1-php8.4-bookworm`) |
| Laravel | 13.40 |
| Filament | 5.11 |
| Livewire | 4.4 |
| PostgreSQL | 17 (Docker / Railway), tests CI sur 16 |
| Pest | 5.3 |
| spatie/laravel-permission | 8.3 |
| spatie/laravel-activitylog | 5.1 |
| spatie/laravel-backup | 10.3 |

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

### Ajouter une permission (phases suivantes)

1. Ajouter un cas dans `app/Enums/Permission.php`.
2. Ajouter sa traduction dans `lang/fr/permissions.php` et `lang/ar/permissions.php`.
3. Déployer : `db:seed` (lancé automatiquement au démarrage) crée la permission et l'ajoute au propriétaire.

## Déploiement Railway

Une seule image Docker (`Dockerfile`), deux services Railway :

| Service | Commande de démarrage | Rôle |
|---|---|---|
| `web` | *(par défaut)* | migrations + données de référence + serveur web |
| `scheduler` | `/usr/local/bin/entrypoint.sh scheduler` | tâches planifiées (sauvegarde quotidienne) |

Plus un service PostgreSQL Railway.

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

Version du client PostgreSQL : `pg_dump` doit être de version ≥ au serveur. L'image installe la
version 17 ; si le PostgreSQL de Railway est plus récent, définir la variable de build
`PG_CLIENT_VERSION` (ex. `18`).

Premier déploiement – compte propriétaire, au choix :

- **Console Railway** : `php artisan app:create-owner` (interactif) ;
- **sans console** : ajouter temporairement `OWNER_NAME`, `OWNER_EMAIL`, `OWNER_PASSWORD`
  (≥ 12 caractères, lettres et chiffres). Le compte est créé au démarrage s'il n'existe aucun
  propriétaire ; **supprimer ensuite ces trois variables**. Elles sont ignorées dès qu'un propriétaire existe.

## Sauvegardes et restauration

- Automatique tous les jours à 02:00 (heure du Maroc) : base de données + fichiers envoyés,
  vers un stockage S3 compatible **externe à Railway** (Cloudflare R2, Backblaze B2…).
- Conservation : tous les jours pendant 30 jours, puis dégressive.
- Contrôle de santé à 08:00 ; email en cas d'échec.
- Le test automatisé `BackupRestoreTest` sauvegarde une base, la détruit puis la restaure.

Restauration (remplace **toutes** les données actuelles) :

```bash
php artisan app:backup-restore            # choisir la sauvegarde dans la liste
php artisan app:backup-restore --force    # dernière sauvegarde, sans confirmation
```

Sauvegarde manuelle : `php artisan backup:run`.

## Sécurité

- Aucun compte par défaut ; le propriétaire est créé par `app:create-owner` (mot de passe ≥ 12 caractères).
- Connexion limitée à 5 tentatives par minute ; comptes désactivables (jamais supprimés).
- Toutes les pages vérifient les permissions côté serveur (Policies), testé par URL directe.
- Journal d'activité non modifiable (protégé par trigger PostgreSQL).
- `.env` et secrets jamais commités.
