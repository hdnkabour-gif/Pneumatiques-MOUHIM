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
