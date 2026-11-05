# Changelog

## [Non publié]

### Ajouté
- Création du premier propriétaire depuis les variables `OWNER_*` au démarrage du conteneur
  (`app:create-owner --from-env`), pour les hébergements sans console disponible. Sans effet si un
  propriétaire existe déjà ; un mot de passe trop faible est refusé sans bloquer le démarrage.

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
