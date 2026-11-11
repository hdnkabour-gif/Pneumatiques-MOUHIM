# Complément du référentiel véhicules – marché marocain (novembre 2026)

Mise à jour : 11 novembre 2026. Fichier de données : `database/data/morocco_reference_supplement.json`.
Chargé automatiquement à chaque déploiement par `MoroccoVehicleReferenceSeeder` (décisions D-59 et D-60).

## Trois niveaux de confiance

| Origine | Statut à l'arrivée | Contenu |
|---|---|---|
| Document officiel marocain (constructeur, importateur, fabricant d'huile) | « À contrôler » | Motorisations, pneus, huiles par moteur, huiles TotalEnergies / Elf |
| Document officiel marocain avec une valeur douteuse | « Non vérifié » + raison | Quelques pneus Kia / Hyundai |
| Fiche technique d'un autre marché (France) pour un modèle vendu au Maroc | « Non vérifié » + mention « Marché France » | Dimensions de pneus uniquement |

**Rien n'est « Vérifié »** et rien de « Non vérifié » n'est présenté au vendeur comme compatible (D-24).
Tout se corrige dans l'application : *Recherche par véhicule › Plus › Compatibilités pneus / huiles,
Motorisations, Générations* et *Produits › Plus › Références pneus et huiles* (bouton « Modifier »,
permission `compatibilities.manage`). Une correction n'est jamais écrasée par un déploiement.

## 1. Documents officiels marocains

- 36 sources : brochures Dacia / Renault Maroc, fiches Kia Maroc et Hyundai Maroc, brochure Nissan Maroc,
  manuels constructeur Kia (ligne « Maroc »), catalogue TotalEnergies Maroc. Consultés le 11 novembre 2026.
- 42 motorisations (dont 7 du classeur complétées), 29 montes de pneus, 2 préconisations d'huile.
- 7 nouveaux modèles : Dacia Bigster, Kia Sonet, Carnival, K5, Tasman, Hyundai Staria, Sonata.
- Ce que la source ne publie pas reste vide (codes moteur, années, indices de charge et de vitesse).

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

### Valeurs douteuses relevées sur les documents officiels (« Non vérifié »)

- Kia Sorento Hybrid : pneus 235/55 R17 (dimension inhabituelle pour ce modèle).
- Hyundai Staria : pneus 215/55 R18 (largeur inhabituelle pour ce modèle).
- Hyundai H100 : arrière publié « 145/R13 ».
- Kia Seltos diesel : la fiche affiche « 215/60R17 65H » ; l'indice 65 n'est pas repris.
- Kia Sorento diesel (« 202 »), Sorento Hybrid (« 215 ») et Tasman (« 141 ch ») : puissance non saisie.
- Kia K2500 : arrière 5.00R12 (dimension non métrique, non enregistrée).

## 2. Huiles : catalogue officiel TotalEnergies Maroc

27 huiles moteur pour voitures, lues sur `lubricants.catalog.totalenergies.com/MAROC`, avec viscosité,
normes ACEA / API, homologations constructeur et conditionnements. Visibles dans *Références huiles*.
Elles disent ce que l'huile respecte, pas quel véhicule la demande.

| Marque | Gamme | Viscosité | ACEA | API |
|---|---|---|---|---|
| Elf | EVOL. 700 ST 10W40 | 10W-40 | A3/B4 | CF, SL |
| Elf | EVOL. PRO TECH 15W40 | 15W-40 | A3/B3 | CF, SL |
| Elf | EVOL. PRO TECH C3 5W30 (ELF) | 5W-30 | C3 | SN PLUS |
| Elf | EVOL. RN TECH 10W40 | 10W-40 | A3/B4 | CF, SL |
| Elf | EVOL. RN TECH 5W40 | 5W-40 | A3/B4 | CF, SN |
| Elf | EVOLUTION 500 TS 15W-40 | 15W-40 | — | CF, SL |
| Elf | EVOLUTION 900 FT 5W-40 | 5W-40 | A3/B4 | SP |
| Elf | EVOLUTION 900 SXR 5W-40 | 5W-40 | A3/B4 | SP |
| Elf | EVOLUTION FULL-TECH MSX 5W-30 | 5W-30 | C3 | SP |
| TotalEnergies | QUARTZ 5000 15W40 | 15W-40 | — | CF, SL |
| TotalEnergies | QUARTZ 7000 15W-50 | 15W-50 | A3/B4 | CF, SL |
| TotalEnergies | QUARTZ 7000 S 10W-40 | 10W-40 | A3/B4 | CF, SN |
| TotalEnergies | QUARTZ 9000 5W-40 | 5W-40 | A3/B4 | CF, SP |
| TotalEnergies | QUARTZ 9000 ENERGY 0W-40 | 0W-40 | A3/B4 | SP |
| TotalEnergies | QUARTZ INEO ECS 5W-30 | 5W-30 | C2 | — |
| TotalEnergies | QUARTZ INEO FIRST 0W-30 | 0W-30 | C2 | SN PLUS |
| TotalEnergies | QUARTZ INEO FIRST 0W20 (TOT) | 0W-20 | C5 | — |
| TotalEnergies | QUARTZ INEO HTC 5W-30 | 5W-30 | C3 | SN PLUS |
| TotalEnergies | QUARTZ INEO LONG LIFE 0W-30 | 0W-30 | C3 | — |
| TotalEnergies | QUARTZ INEO LONG LIFE 0W20 | 0W-20 | C5 | — |
| TotalEnergies | QUARTZ INEO LONG LIFE 5W-30 | 5W-30 | C3 | SP |
| TotalEnergies | QUARTZ INEO MC3 5W-30 | 5W-30 | C3 | SP |
| TotalEnergies | QUARTZ INEO MC3 5W40 | 5W-40 | C3 | CF, SN |
| TotalEnergies | QUARTZ INEO XTRA DYNAMICS 0W-20 | 0W-20 | C5 | SN |
| TotalEnergies | QUARTZ INEO XTRA LONG LIFE 0W-20 | 0W-20 | C5, C6 | SP |
| TotalEnergies | QUARTZ RACING 10W-60 | 10W-60 | — | CF, SN |
| TotalEnergies | Quartz 5000 20W-50 | 20W-50 | — | CF, SL |

## 3. Pneus : fiches techniques du marché français (D-60)

Les sites marocains de Peugeot, Citroën, Opel et Fiat refusent la lecture automatique, et ceux de
Volkswagen, Škoda, SEAT, Audi et Toyota ne publient pas de fiche technique. Pour ces modèles vendus au Maroc,
les dimensions de pneus ont été lues sur les fiches techniques françaises de L'argus (millésime indiqué dans
chaque source) : 153 montes pour 48 modèles.

**Limites à connaître :**
- les finitions, les jantes et parfois la voiture elle-même diffèrent d'un marché à l'autre (par exemple la
  Citroën C3 ou la Fiat Tipo) : la dimension est une piste, à confirmer sur l'étiquette de portière ;
- chaque ligne liste les versions françaises concernées, y compris sportives ou électriques ;
- les indices de charge et de vitesse ne sont pas publiés ;
- **aucune motorisation ni huile** n'est reprise d'un autre marché.

| Marque | Modèle | Dimensions relevées (France) |
|---|---|---|
| Audi | Q3 | 215/65 R17, 235/55 R18, 235/60 R17, 255/45 R19 |
| Audi | Q5 | 235/55 R19, 235/60 R18, 255/45 R20 |
| BYD | Seal U | 235/50 R19 |
| Citroën | Berlingo | 205/60 R16 |
| Citroën | C3 | 205/50 R17, 205/55 R16 |
| Citroën | C3 Aircross | 205/50 R17, 205/55 R16, 215/60 R17, 215/65 R16 |
| Citroën | C4 | 195/60 R18, 215/65 R16 |
| Citroën | C5 Aircross | 225/55 R19, 225/60 R18, 235/55 R18, 235/55 R19 |
| Dacia | Bigster | 215/60 R18, 215/65 R17, 225/55 R18, 225/60 R17 |
| Fiat | 500 | 185/55 R15, 185/65 R15, 195/45 R16, 195/55 R16, 205/45 R17 |
| Fiat | Panda | 175/65 R14, 175/65 R15, 185/55 R15 |
| Fiat | Tipo | 195/65 R15, 205/55 R16 |
| Ford | Kuga | 225/55 R19, 225/60 R18, 225/65 R17 |
| Ford | Puma | 215/50 R18, 215/55 R17, 225/40 R19 |
| Kia | Sportage | 215/65 R17, 235/50 R19, 235/55 R18 |
| Nissan | Qashqai | 215/65 R17, 235/45 R20, 235/50 R19, 235/55 R18 |
| Opel | Astra | 205/55 R16, 215/45 R18, 225/40 R18, 225/45 R17 |
| Opel | Corsa | 195/55 R16, 205/45 R17 |
| Opel | Grandland | 225/55 R18, 225/55 R19, 235/50 R20, 235/55 R19 |
| Opel | Mokka | 195/60 R18, 215/60 R17, 215/65 R16, 225/40 R20 |
| Peugeot | 2008 | 215/55 R18, 215/60 R17, 215/65 R16 |
| Peugeot | 208 | 195/55 R16, 205/45 R17 |
| Peugeot | 3008 | 225/55 R19, 235/50 R20, 235/55 R19 |
| Peugeot | 308 | 205/55 R16, 215/45 R18, 225/40 R18, 225/45 R17 |
| Peugeot | 5008 | 215/65 R17, 225/55 R18, 225/55 R19, 225/60 R18, 235/50 R20, 235/55 R19 |
| Peugeot | Rifter | 215/60 R17, 215/65 R16 |
| Renault | Arkana | 215/55 R18, 215/60 R17, 225/45 R19 |
| Renault | Captur | 215/55 R18, 215/60 R17, 225/45 R19 |
| Renault | Clio | 195/55 R16, 195/60 R16, 205/45 R17, 205/45 R18 |
| SEAT | Arona | 205/55 R17, 205/60 R16 |
| SEAT | Ateca | 215/50 R18, 215/55 R17 |
| SEAT | Ibiza | 185/65 R15, 195/55 R16, 215/45 R17 |
| SEAT | Leon | 205/55 R16, 225/45 R17 |
| Toyota | C-HR | 235/50 R20, 235/60 R18 |
| Toyota | Corolla | 205/55 R16, 225/40 R18, 225/45 R17 |
| Toyota | RAV4 | 225/55 R19, 225/60 R18, 225/65 R17, 235/50 R20, 235/60 R18 |
| Toyota | Yaris | 185/65 R15, 195/55 R16, 205/45 R17, 215/40 R18, 225/45 R18 |
| Toyota | Yaris Cross | 205/65 R16, 215/50 R18, 215/55 R17 |
| Volkswagen | Golf | 205/55 R16, 225/40 R18, 225/45 R17, 235/35 R19 |
| Volkswagen | Polo | 185/65 R15, 195/55 R16, 215/45 R17 |
| Volkswagen | T-Roc | 215/50 R18, 215/55 R17, 215/60 R16, 225/50 R18, 225/55 R17, 225/60 R16 |
| Volkswagen | Tiguan | 215/65 R17, 235/55 R18, 255/45 R19 |
| Volkswagen | Touareg | 255/55 R19, 285/40 R21 |
| Škoda | Fabia | 185/65 R15, 195/55 R16, 215/45 R17, 215/45 R18 |
| Škoda | Kamiq | 205/55 R17, 205/60 R16 |
| Škoda | Karoq | 215/50 R18, 215/55 R17, 225/50 R18 |
| Škoda | Kodiaq | 215/65 R17, 235/45 R20, 235/50 R19 |
| Škoda | Octavia | 205/55 R17, 225/45 R18 |

## Ce qui reste à obtenir

| Sujet | Raison | Ce qu'il faut |
|---|---|---|
| Motorisations et huiles Peugeot, Citroën, Opel, Fiat, Volkswagen, Škoda, SEAT, Audi, Toyota | Pas de document marocain lisible ; non repris d'un autre marché car les moteurs changent d'un pays à l'autre. | Fiches techniques de l'importateur (PDF à déposer dans `docs/data/`) ou saisie dans l'application |
| Huiles Renault / Dacia des nouvelles motorisations, huiles Hyundai, Nissan, Kia Sportage / Seltos | Aucune source ne rattache ces moteurs sans supposition. | Carnet d'entretien ou manuel du véhicule |
| Huiles Shell, Castrol, Mobil, Motul, Liqui Moly… | Pas de catalogue produit lisible pour le Maroc dans cette session (pages dynamiques ou introuvables). | Fiches techniques des fournisseurs, ou saisie dans *Références huiles* |
| Disponibilité des marques de pneus au Maroc | Aucune source officielle par marque. | Liste des fournisseurs de l'entreprise |
| Nissan Patrol | La brochure en ligne est celle d'un marché à conduite à droite. | Fiche Nissan Maroc |

## Règles

- Les lignes déjà présentes ne sont jamais écrasées : notes du responsable, corrections, compatibilités
  vérifiées ou obsolètes. Seuls les champs vides d'une gamme ou d'une motorisation sont complétés.
- Aucun produit, prix, SKU ou stock n'est créé.
- Pour ajouter des données : nouvelles lignes dans le fichier JSON (mêmes colonnes que le classeur V1.3),
  chacune avec sa source ; les tests `MoroccoVehicleReferenceTest` refusent une ligne sans source.
