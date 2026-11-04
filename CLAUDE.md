# CLAUDE.md – PNEU MOUHIM Manager

Ce fichier contient les règles permanentes du projet. Lis-le entièrement avant toute tâche.

Documents de référence obligatoires :

- `docs/cahier-des-charges.md` : spécifications fonctionnelles et techniques complètes.
- `docs/decisions.md` : décisions déjà prises. Elles ne se rediscutent pas sans validation explicite.

En cas de contradiction : `docs/decisions.md` > `docs/cahier-des-charges.md` > ce fichier > tes propres suppositions.

---

## 1. Contexte

PNEU MOUHIM est une entreprise marocaine de pneumatiques et de services automobiles liés (vidange, filtres, batteries, éclairage, parallélisme, équilibrage, réparation).

L'entreprise possède **trois sites** : un Dépôt Central et deux magasins avec atelier (Magasin 01, Magasin 02).

L'application est l'outil de gestion du **propriétaire** : il doit voir et contrôler tout ce qui se passe dans les trois sites (ventes, achats, stocks, transferts, crédits, caisse, ateliers, maintenance), et ses employés doivent pouvoir travailler vite et simplement.

C'est un **projet nouveau, construit de zéro**. Il n'existe aucun ancien code à migrer ou à préserver.

---

## 2. Stack technique

- Laravel (dernière version stable) + PHP compatible.
- Filament pour l'interface d'administration.
- Livewire pour les écrans métier rapides (caisse / vente, écran technicien).
- PostgreSQL.
- Docker / docker-compose (même configuration en local et sur Railway).
- Tests : Pest.
- PDF : mPDF (support correct de l'arabe).
- Dépôt GitHub privé, déploiement Railway (région Europe).

Vérifie la compatibilité exacte des versions Laravel / Filament / PHP / PostgreSQL au moment de l'initialisation et documente-les dans `README.md`.

Aucune dépendance payante sans validation explicite.

---

## 3. Méthode de travail (obligatoire)

1. Travailler **une phase à la fois**, dans l'ordre défini dans `docs/cahier-des-charges.md` (section « Phases »).
2. Au début de chaque phase : présenter un plan court (tables, écrans, tests prévus) et attendre validation.
3. Ne jamais passer à la phase suivante tant que les tests de la phase actuelle ne passent pas.
4. Ne jamais annoncer qu'une fonctionnalité est terminée sans tests qui le prouvent.
5. Si une spécification est ambiguë ou semble incorrecte : **poser la question**, ne pas deviner.
6. Toute nouvelle décision technique ou métier validée est ajoutée dans `docs/decisions.md`.
7. Commits petits et clairs, en anglais, format conventionnel (`feat:`, `fix:`, `test:`, `docs:`, `refactor:`).
8. À la fin de chaque phase : mettre à jour `README.md` et `docs/CHANGELOG.md`.

---

## 4. Règles métier non négociables

- Le stock n'est **jamais** modifié directement : toute variation passe par un mouvement de stock enregistré (auteur, date, site, motif, quantité, document lié).
- Toute opération sensible (vente, paiement, achat, transfert, ajustement, clôture de caisse) s'exécute dans une **transaction** de base de données avec verrouillage approprié.
- Une facture validée n'est **jamais supprimée** ni modifiée : correction uniquement par avoir.
- Les montants sont stockés en **centimes (entiers)**, jamais en float.
- Toutes les permissions sont vérifiées **côté serveur** (Policies), pas seulement masquées dans l'interface.
- Toute donnée liée à un site (stock, vente, caisse, achat, ordre de travail) possède un `site_id`.
- Un pneu d'occasion qui échoue au contrôle de sécurité est bloqué à la vente.
- Ne jamais inventer une compatibilité véhicule / produit. Toute compatibilité a une source et un statut.
- Ne jamais calculer un bénéfice avec un coût d'achat absent : signaler la donnée manquante.
- Aucune donnée fictive présentée comme réelle. Les données de démonstration sont dans un seeder séparé (`DemoSeeder`), jamais exécuté en production.

---

## 5. Règles de simplicité (UX)

L'application est utilisée par des vendeurs et des techniciens pressés, sur PC, tablette et téléphone. La simplicité est une exigence, pas une option.

- Une vente standard doit pouvoir être réalisée **en moins d'une minute**, sur un seul écran.
- Une **barre de recherche unique** comprend : dimension (`205 55 16`, `2055516`, `205/55R16`), immatriculation, nom, téléphone, référence, SKU.
- Valeurs par défaut partout : site de l'utilisateur, TVA par défaut, date du jour, paiement en espèces.
- Champs obligatoires réduits au strict minimum ; champs avancés repliés (« Plus de détails »).
- Boutons larges et utilisables au doigt sur tablette et téléphone.
- L'écran de vente et l'écran technicien ne sont **pas** des tables Filament : ce sont des écrans Livewire dédiés, rapides et épurés.
- Chaque utilisateur choisit sa langue (français ou arabe, RTL géré). Les documents commerciaux sont en français par défaut.
- Aucun texte d'interface codé en dur : tout passe par les fichiers de traduction `lang/fr` et `lang/ar`.
- Messages d'erreur clairs, compréhensibles par un non-informaticien.

---

## 6. Conventions de code

- Code, noms de tables, colonnes, classes et variables en **anglais**.
- Logique métier dans des classes `Actions` / `Services` dédiées (ex. `ConfirmSaleAction`, `TransferStockAction`), jamais dans les composants d'interface.
- Enums PHP pour tous les statuts.
- Migrations propres, versionnées, jamais modifiées après déploiement (nouvelle migration à la place).
- Index sur les champs de recherche fréquents (dimensions, immatriculation, téléphone, SKU, références).
- Pas de requêtes N+1 : utiliser l'eager loading.
- Journal d'activité sur toutes les opérations sensibles.

---

## 7. Sécurité

- Ne jamais commiter `.env`, secrets, mots de passe, clés.
- Aucun compte par défaut du type `admin/admin` sur un environnement accessible publiquement.
- Limitation des tentatives de connexion.
- Sauvegarde automatique quotidienne de la base vers un stockage externe à Railway, avec procédure de restauration testée.

---

## 8. Définition de « terminé »

Une fonctionnalité est terminée uniquement si :

1. Elle respecte le cahier des charges et les décisions.
2. Les tests automatisés couvrant les cas normaux, les cas d'erreur et les permissions passent.
3. Elle fonctionne en français et en arabe.
4. Elle est utilisable sur téléphone et tablette.
5. Elle est documentée (README / CHANGELOG).
