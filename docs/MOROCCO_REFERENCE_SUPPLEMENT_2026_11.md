# Complément du référentiel véhicules – marché marocain (novembre 2026)

Mise à jour : 11 novembre 2026. Fichier de données : `database/data/morocco_reference_supplement.json`.
Chargé automatiquement à chaque déploiement par `MoroccoVehicleReferenceSeeder` (décision D-59).

## Méthode de vérification

- Chaque valeur a été **lue sur un document officiel marocain** du constructeur ou de l'importateur
  (brochure PDF Dacia / Renault Maroc, fiche « spécificités » Kia Maroc, fiche « spécifications »
  Hyundai Maroc, brochure Nissan Maroc), consulté le 11 novembre 2026. L'adresse exacte est enregistrée
  dans la source de chaque ligne.
- Les huiles viennent des **manuels constructeur Kia en ligne** (ligne « Maroc » du tableau des lubrifiants).
- **Rien n'est déduit** : ce que la source ne publie pas reste vide (codes moteur, années de production,
  indices de charge et de vitesse, carburant de la Kia Sportage 1.6 136 ch…).
- **Rien n'est « Vérifié »** : les lignes arrivent « À contrôler » (document officiel) ou « Non vérifié »
  (valeur douteuse sur le document lui-même). Seul un utilisateur autorisé peut vérifier, avec l'étiquette
  de portière ou la carte grise (D-24).

## Contenu

- 35 sources, 29 gammes, 42 motorisations
  (dont 7 motorisations du classeur complétées : cylindrée, couple, kW), 29 montes de pneus,
  2 préconisations d'huile, 11 dimensions ajoutées au catalogue de référence.
- 7 nouveaux modèles commercialisés au Maroc : Dacia Bigster, Kia Sonet, Kia Carnival, Kia K5, Kia Tasman,
  Hyundai Staria, Hyundai Sonata.

| Marque | Modèle | Motorisations | Montes pneus | Huiles |
|---|---|---:|---:|---:|
| Dacia | Logan | 3 | 0 | 0 |
| Dacia | Sandero | 3 | 2 | 0 |
| Dacia | Sandero Stepway | 2 | 0 | 0 |
| Dacia | Duster | 1 | 2 | 0 |
| Dacia | Jogger | 2 | 1 | 0 |
| Dacia | Bigster | 2 | 0 | 0 |
| Renault | Kardian | 2 | 0 | 0 |
| Renault | Captur | 2 | 0 | 0 |
| Renault | Arkana | 1 | 0 | 0 |
| Renault | Express | 1 | 0 | 0 |
| Renault | Express Van | 1 | 0 | 0 |
| Renault | Mégane Sedan | 1 | 2 | 0 |
| Kia | Sportage | 2 | 0 | 0 |
| Kia | Seltos | 2 | 3 | 0 |
| Kia | Sonet | 1 | 1 | 1 |
| Kia | Sorento | 2 | 2 | 1 |
| Kia | Carnival | 1 | 2 | 0 |
| Kia | K5 | 1 | 1 | 0 |
| Kia | Tasman | 1 | 1 | 0 |
| Kia | K2500 | 1 | 1 | 0 |
| Hyundai | Tucson | 1 | 1 | 0 |
| Hyundai | Creta | 1 | 1 | 0 |
| Hyundai | Kona | 1 | 1 | 0 |
| Hyundai | Staria | 1 | 1 | 0 |
| Hyundai | i20 | 1 | 1 | 0 |
| Hyundai | Elantra | 1 | 1 | 0 |
| Hyundai | Santa Fe | 1 | 1 | 0 |
| Hyundai | H100 | 1 | 2 | 0 |
| Hyundai | Sonata | 1 | 1 | 0 |
| Nissan | Magnite | 1 | 1 | 0 |

## Valeurs douteuses relevées sur les documents officiels

Elles sont enregistrées telles que publiées, en « Non vérifié », avec la raison dans la note :

- Kia Sorento Hybrid : pneus 235/55 R17 (dimension inhabituelle pour ce modèle).
- Hyundai Staria : pneus 215/55 R18 (largeur inhabituelle pour ce modèle).
- Hyundai H100 : arrière publié « 145/R13 ».
- Kia Seltos diesel : la fiche affiche « 215/60R17 65H » ; l'indice 65 n'est pas repris.
- Kia Sorento diesel (« 202 »), Sorento Hybrid (« 215 ») et Tasman (« 141 ch ») : puissance non saisie.
- Kia K2500 : arrière 5.00R12 (dimension non métrique, non enregistrée).

## Ce qui n'a pas pu être vérifié (non ajouté)

| Sujet | Raison | Ce qu'il faut |
|---|---|---|
| Peugeot (208, 2008, 3008, 308…), Citroën, Opel (Corsa), Fiat | Les sites marocains refusent la lecture automatique des fiches techniques (HTTP 403). Les fiches existent, par exemple `peugeot.ma/content/dam/peugeot/morocco/brochure/new-fiche-technique/F-T-208-v2.pdf`. | Télécharger les PDF à la main et les déposer dans `docs/data/` |
| Volkswagen (T-Roc, Touareg), Škoda (Kodiaq), SEAT (Ateca), Audi (Q3), Toyota, BYD (Seal U) | Aucune fiche technique publique lisible sur les sites marocains (brochure sur demande ou configurateur). | Fiches techniques de l'importateur |
| Renault Kardian, Clio 6, Captur, Arkana, Express ; Dacia Bigster ; Kia Sportage | Les brochures Maroc ne publient pas les dimensions de pneus. | Étiquette de portière du véhicule |
| Huiles Renault / Dacia des nouvelles motorisations (Hybrid 155, TCe 150, dCi 102…) | Le catalogue Renault disponible (autre marché) ne permet pas de rattacher ces moteurs sans supposition. | Carnet d'entretien ou manuel du véhicule |
| Huiles Hyundai, Nissan, Kia Sportage / Seltos | Pas de manuel constructeur accessible pour la version vendue au Maroc. | Manuel du véhicule |
| Nissan Patrol | La brochure en ligne est celle d'un marché à conduite à droite. | Fiche Nissan Maroc |
| Gammes d'huiles (ACEA, API, homologations) et disponibilité des marques de pneus au Maroc | Fiches techniques produit non accessibles dans cette session. | Fiches techniques des fournisseurs |

## Règles

- Les lignes déjà présentes ne sont jamais écrasées : notes du responsable, compatibilités vérifiées ou
  obsolètes, valeurs déjà saisies. Seuls les champs vides d'une gamme ou d'une motorisation sont complétés.
- Aucun produit, prix, SKU ou stock n'est créé.
- Pour ajouter des données : nouvelles lignes dans le fichier JSON (mêmes colonnes que le classeur V1.3),
  chacune avec sa source ; les tests `MoroccoVehicleReferenceTest` refusent une ligne sans source.
