# Plan — stockage durable des documents privés, inventaire avec empreintes et restauration hors site (#29)

> **Statut : PLAN, non exécuté.** Ce document ne modifie rien en production. Chaque étape marquée
> 🔒 exige une **approbation séparée du propriétaire**. Aucune donnée réelle n'est copiée dans Git,
> dans NEXUS ou dans un ticket. Aucun justificatif n'est ouvert : seules des empreintes SHA-256 sont produites.

## 1. Situation (constatée en lecture seule, revue Astra 24-11-2026)
- Railway : les services `web` de `main` et de staging montent **un seul volume, sur `/app/storage/app/public`**.
- Les justificatifs de dépenses (photos de reçus) étaient sur ce disque public (servis sous `/storage`).
- Le code de #31 (`8c04924`) :
  - refuse de déplacer un justificatif tant que le disque privé `documents` (`storage/app/documents`) n'est pas **dans le volume persistant** et **couvert par les sauvegardes** (`DocumentStorageGuard`) ;
  - refuse tout nouveau justificatif dans ce cas ;
  - au démarrage, `app:secure-receipts --boot` **empêche l'ouverture du serveur** tant qu'un justificatif reste sur le disque public.
- Conséquence : déployer #27/#31 sur l'environnement actuel **sans** changer le volume ne démarre pas s'il existe d'anciens justificatifs publics. C'est voulu (fail-closed).

## 2. Topologie cible
| Élément | Cible |
|---|---|
| Volume Railway du service `web` | **un volume monté sur `/app/storage/app`** (contient `public/`, `documents/`, sauvegardes locales) |
| `RAILWAY_VOLUME_MOUNT_PATH` | `/app/storage/app` (fourni par Railway) → le garde passe |
| Worker / scheduler | sans volume (inchangé) ; les sauvegardes partent vers le stockage externe |
| Sauvegardes | `backup:run` quotidien 02:00 vers un stockage S3 **externe à Railway** (D-37 : R2 ou B2, 🔒 à choisir), archive chiffrée (`BACKUP_ARCHIVE_PASSWORD`), base + `storage/app/public` + `storage/app/documents` |
| Contrôle | `app:go-live-audit --strict` doit afficher `Private documents storage: PASS` et `Invoice numbering approval: PASS` |

## 3. Deux chemins — décision 🔒 du propriétaire
**A. Recommandé : nouvel environnement de production propre** (déjà retenu : « nouvel env propre, sans copier les données de démonstration »).
Le volume est monté sur `/app/storage/app` dès le premier démarrage : **aucun justificatif historique, donc aucun transfert**. L'environnement de démonstration actuel reste intact (ni supprimé, ni modifié).

**B. Seulement si des justificatifs réels de l'environnement actuel doivent être conservés.** Procédure de transfert ci-dessous (§5), avec retour arrière.

## 4. Pré-requis communs (🔒 approbations)
1. Fournisseur de sauvegarde externe (R2/B2), bucket dédié, clés en variables Railway (jamais dans Git).
2. Fenêtre de mise en service et personne d'astreinte.
3. Revue indépendante acceptée de #31/#32, fusion dans #27, CI verte sur la tête exacte de #27.
4. Premier numéro de facture papier validé (#20).

## 5. Chemin B — transfert avec empreintes (si décidé)
Toutes les commandes s'exécutent dans le conteneur du service concerné ; les sorties d'inventaire sont
conservées **hors dépôt**, dans un emplacement privé choisi par le propriétaire.

| # | Étape | Commande / action | Critère de passage |
|---|---|---|---|
| B1 | Gel des écritures | mode maintenance (`php artisan down`) 🔒 | plus aucune dépense saisie |
| B2 | Sauvegarde fraîche | `php artisan backup:run` puis `backup:list` | archive présente sur le stockage externe, taille > 0 |
| B3 | Inventaire **avant** | `php artisan app:receipts-inventory --json > avant.jsonl` | ligne `RECEIPTS_INVENTORY … missing=0 mismatch=0` (sinon : arrêt, signaler les manquants, ne rien inventer) |
| B4 | Nouveau volume | 🔒 créer un volume monté sur `/app/storage/app` ; **l'ancien volume reste détaché et intact** (retour arrière) | variable `RAILWAY_VOLUME_MOUNT_PATH=/app/storage/app` |
| B5 | Remise en place des fichiers | depuis l'archive B2 : `php artisan app:backup-restore <archive> --files-only --files-to=/app/storage/app/public --documents-to=/app/storage/app/documents` | commande réussie |
| B6 | Inventaire **après restauration** | `app:receipts-inventory --json > apres.jsonl` ; comparer `path` + `sha256` avec `avant.jsonl` | **égalité stricte** des empreintes, même nombre de lignes |
| B7 | Sécurisation | démarrage normal : `app:secure-receipts --boot` déplace vers `documents` (copie, vérification SHA-256, suppression publique) | `SECURE_RECEIPTS status=OK … exposed=0 safe=yes`, le serveur démarre |
| B8 | Inventaire final | `app:receipts-inventory` | tous `private`, `public=0`, `missing=0`, `mismatch=0`, empreintes identiques à B3 |
| B9 | Contrôles | `app:go-live-audit` ; ouvrir un justificatif depuis l'écran caisse (droit caisse + site) ; vérifier que l'URL publique `/storage/expenses/...` renvoie 404 | PASS |
| B10 | Sortie de maintenance | `php artisan up` 🔒 | — |

**Retour arrière** (avant B10) : remonter l'ancien volume sur `/app/storage/app/public`, redéployer la version précédente (`ea55329`). Rien n'a été supprimé de l'ancien volume.

## 6. Restauration hors site, en environnement isolé (obligatoire avant la mise en service)
Objectif : prouver qu'une archive **du stockage externe** permet de reconstruire base + justificatifs, sans toucher la production.
1. Télécharger l'archive du jour depuis le stockage externe (clé en lecture seule) sur une machine isolée.
2. Rejouer avec les scripts NEXUS `release-gate/2026-11-20/pneu/` (réseau Docker `--internal`, PostgreSQL 18 neuf, image de la tête exacte de #27) :
   `app:backup-restore <archive> --into=<nouvelle_base> --files-to=… --documents-to=…`.
3. Vérifier :
   - empreinte des tables (`fingerprint`) identique à la source ;
   - `app:receipts-inventory` : mêmes empreintes que l'inventaire de production du même jour ;
   - `stock:verify` OK ;
   - rapprochement des soldes (crédits clients, caisse, fournisseurs) identique ;
   - `app:go-live-audit` exécuté sur la copie.
4. Conserver comme preuve uniquement : SHA de l'image, nom et taille de l'archive, résultats PASS/FAIL, compteurs. **Jamais** de contenu, de nom de client ni de justificatif.
5. Détruire la copie isolée à la fin.

## 7. Liste de contrôle « go / no-go »
- [ ] Revue indépendante #31/#32 acceptée, CI verte sur la tête exacte de #27
- [ ] Volume `/app/storage/app`, `go-live-audit --strict` = PASS (documents + numérotation)
- [ ] Sauvegarde externe quotidienne active, alerte d'échec testée
- [ ] Restauration hors site isolée réussie (§6), preuves enregistrées
- [ ] Chemin B uniquement : inventaires avant/après identiques (B3 = B6 = B8)
- [ ] Recette en magasin avec le propriétaire
- [ ] Approbation écrite du propriétaire pour le déploiement
