# Référentiel maître – PNEU MOUHIM Manager

**État : fondation documentaire – 6 novembre 2026**  
**Branche :** `docs/reference-catalogue-foundation`

Ce dossier conserve le travail de normalisation et d'enrichissement du catalogue. Il ne constitue pas encore un import de données dans la base de production.

## Sources et limites actuelles

1. Classeur attendu : `docs/data/PNEU_MOUHIM_Manager_Catalogue_Operations_V1_3.xlsx` (SHA connu : `268d6d337905b7f383af24b8d5f574c34aecd8b3`).
2. Les spécifications du projet sont dans `docs/cahier-des-charges.md` et les règles validées dans `docs/decisions.md`.
3. Le contenu binaire du classeur V1_3 n'a pas encore pu être ouvert dans cet environnement. **Aucune ligne, aucun nombre de lignes et aucune valeur du classeur ne sont déclarés comme extraits ou comparés.** Il faut encore obtenir une lecture réelle du XLSX.
4. Les listes de marques dans ce dossier sont des **listes candidates de départ**, pas des catalogues exhaustifs ni des compatibilités véhicule-produit certifiées.

## Fichiers

- `catalogue-scope.md` : domaines fonctionnels, attributs à couvrir et règles de recherche.
- `candidate-brands.csv` : marques candidates pour démarrer les référentiels, sans modèles ni compatibilités supposés.
- `data-quality-and-import.md` : dédoublonnage, provenance, statuts, comparaison Excel/base et contrôles d'import.

## Règles non négociables

- Ne jamais écraser les données déjà présentes dans l'application par une liste candidate.
- Ne jamais fusionner deux générations, moteurs, dimensions, indices ou références uniquement parce que leurs noms se ressemblent.
- Une compatibilité pneu/huile/filtre/batterie/éclairage/frein doit être liée à une variante moteur/génération/année lorsque nécessaire.
- Seules les lignes avec statut `Vérifié` apparaissent comme compatibilités confirmées à la vente. L'import initial ne doit jamais attribuer ce statut.
- Garder la source, URL, date de vérification, auteur, statut et notes de chaque donnée technique.
- Les références catalogue ne sont pas des produits de stock : un produit commercial PNEU MOUHIM (SKU, prix, coût, fournisseur, DOT, site, quantité) reste distinct du modèle générique.
- Phase actuelle huile : bidons fermés uniquement. Phase ultérieure souhaitée : prise en charge simultanée des bidons fermés et des fûts/vrac, sans remplacer le mode bidon. Cette évolution n'autorise pas la vente au litre en V1 (D-06).

## Prochaine étape technique

Lire réellement le XLSX depuis le dépôt (ou depuis un environnement de développement autorisé), exporter chaque feuille en CSV/JSON avec son nom et ses en-têtes, puis produire un rapport comparatif avant tout import. Ne pas déclencher de déploiement Railway pour cette opération.
