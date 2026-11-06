# Qualité, comparaison et import du référentiel

## 1. Ne pas confondre trois couches

1. **Référence générique** : constructeur, modèle, génération, moteur, marque pneu/huile, spécification.
2. **Compatibilité technique** : relation entre variante de véhicule et dimension/spécification, avec preuve.
3. **Article commercial** : SKU PNEU MOUHIM, prix, coût, fournisseur, conditionnement, stock par site, lot DOT ou numéro de série.

Une fiche de référence ne crée jamais automatiquement un produit en stock.

## 2. Statuts

- `Vérifié` : preuve appropriée consultée, correspondance exacte de génération/moteur/année/marché ; source enregistrée.
- `Non vérifié` : donnée importée ou saisie sans preuve suffisante.
- `À contrôler` : conflit, champ manquant, variante ambiguë ou preuve partielle.
- `Obsolète` : donnée historiquement valable mais remplacée ou qui n'est plus applicable.

À l'import, aucune ligne ne devient `Vérifié` automatiquement. Une validation ultérieure exige la permission `compatibilities.verify`, une source et une entrée de journal d'activité.

## 3. Provenance obligatoire

Pour chaque donnée technique ou relation de compatibilité, conserver au minimum :
- `source_name`
- `source_url` (si disponible)
- `source_document` / référence de page ou section
- `verified_at`
- `created_by` / `verified_by`
- `verification_status`
- `notes`
- date d'import et identifiant de lot d'import

Sources à privilégier :
1. Manuel du propriétaire / documentation officielle du constructeur pour le véhicule et le marché concernés.
2. Fiche technique officielle du fabricant de pneu/huile/pièce et catalogue d'application officiel.
3. Documentation technique d'un fournisseur professionnel sous licence (par ex. TecDoc uniquement si accès/licence autorisés).
4. Données internes de l'entreprise, clairement identifiées comme telles, en attendant validation externe.

Les forums, annonces, blogs SEO et boutiques en ligne ne doivent jamais constituer l'unique preuve d'une compatibilité.

## 4. Normalisation et dédoublonnage

Créer une clé de recherche normalisée (Unicode NFKD, minuscules, retrait des accents, espaces multiples réduits, ponctuation/traits d'union harmonisés). Garder toujours la valeur originale affichée.

Clés candidates :
- Marque : nom officiel normalisé + alias, sans fusionner deux marques distinctes.
- Modèle : marque_id + modèle normalisé.
- Génération : modèle_id + code génération ou intervalle de dates + carrosserie/marché.
- Moteur : génération_id + code moteur ; ne pas fusionner par seule cylindrée.
- Dimension pneu : largeur + ratio + diamètre + indices et attributs séparés.
- Huile : marque + nom de gamme + viscosité + normes/homologations exactes + conditionnement.
- Référence pièce : fabricant + référence fabricant normalisée ; les références équivalentes restent des liens, pas des alias identiques.

Avant fusion, comparer les champs discriminants : années, moteur, carburant, puissance, marché, transmission, indices, homologation et source. En cas de doute, garder deux fiches et marquer `À contrôler`.

## 5. Procédure de comparaison XLSX / application

Quand le fichier XLSX est réellement lisible :

1. Lister toutes les feuilles, dimensions, en-têtes, types, lignes vides et formules.
2. Exporter une copie CSV/JSON par feuille et conserver le classeur original inchangé.
3. Comparer chaque feuille à la table ou au modèle correspondant de l'application.
4. Produire quatre groupes : identique, nouveau, doublon probable, conflit / données manquantes.
5. Détecter les doublons exacts et approximatifs sans les fusionner automatiquement.
6. Valider les colonnes obligatoires et les références entre feuilles.
7. Produire un rapport des compatibilités sans source et des valeurs suspectes.
8. Faire valider les fusions et les corrections par le responsable.
9. Importer d'abord dans une zone temporaire / staging avec aperçu et rapport d'erreurs.
10. Après tests, appliquer les données approuvées avec sauvegarde préalable et journalisation.

## 6. Contrôles automatiques

- Références orphelines : modèle sans marque, moteur sans génération, compatibilité sans variante.
- Années incohérentes : fin avant début, année moteur hors génération.
- Dimensions mal formées, indices manquants, doublons exacts.
- Compatibilité pneu avant/arrière confondue avec monte alternative.
- Huile sans norme, viscosité invalide ou homologation mal recopiée.
- Sources URL vides ou non officielles sur une ligne prétendant être vérifiée.
- Statut `Vérifié` sans vérificateur, date ou preuve.
- Conditionnement huile confondu avec unité de vente.
- Références produit de stock mélangées aux références génériques.

## 7. Tests requis avant intégration

- Import sans perte des colonnes sources.
- Réimport du même fichier sans créer de doublons (idempotence).
- Import partiel / erreur sur une ligne sans corrompre les autres.
- Prévisualisation et annulation.
- Encodage accents/arabe et caractères spéciaux.
- Variantes d'écriture de dimensions.
- Protection des données déjà validées et des historiques de stock.
- Seules les compatibilités vérifiées visibles comme confirmées.
- Journal d'activité et contrôle de permissions.

## 8. État d'avancement réel

- [x] Règles métier et périmètre initial documentés à partir des spécifications du dépôt.
- [x] Première liste candidate de marques automobiles, pneus et huiles préparée.
- [ ] Lecture réelle de toutes les feuilles du classeur V1_3.
- [ ] Comparaison ligne بل ligne avec جداول التطبيق.
- [ ] Extraction des modèles, générations, moteurs, dimensions et normes du classeر.
- [ ] Vérification des compatibilités بمصادر رسمية.
- [ ] Import تجريبي واختبارات.
- [ ] اعتماد نهائي من المسؤول.

**ملاحظة:** البنود غير المكتملة أعلاه لم يتم الادعاء بإنجازها. لا تنشر البيانات المرشحة في الإنتاج قبل المقارنة والاختبار.
