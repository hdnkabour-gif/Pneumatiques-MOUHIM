# Complément du référentiel véhicules – marché marocain (novembre 2026)

Mise à jour : 12 novembre 2026. Fichier de données : `database/data/morocco_reference_supplement.json`.
Chargé automatiquement à chaque déploiement par `MoroccoVehicleReferenceSeeder` (décisions D-59 et D-60).

## Trois niveaux de confiance

| Origine | Statut à l'arrivée | Contenu |
|---|---|---|
| Document officiel marocain (constructeur, importateur, fabricant d'huile) | « À contrôler » | Motorisations, pneus, huiles par moteur, huiles TotalEnergies / Elf |
| Document officiel marocain avec une valeur douteuse | « Non vérifié » + raison | Quelques pneus Kia / Hyundai |
| Guide d'achat marocain (Wandaloo) : versions au tarif Maroc | Information (une motorisation n'a pas de statut) | Motorisations de 61 marques, jantes par version |
| Revendeur marocain (pneu.ma) | « À vérifier » dans les références | Marques, gammes, dimensions et indices des pneus vendus au Maroc |
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
chaque source) : 181 montes pour 58 modèles.

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
| Citroën | C4 X | 195/60 R18, 215/65 R16 |
| Citroën | C5 Aircross | 225/55 R19, 225/60 R18, 235/55 R18, 235/55 R19 |
| Dacia | Bigster | 215/60 R18, 215/65 R17, 225/55 R18, 225/60 R17 |
| Fiat | 500 | 185/55 R15, 185/65 R15, 195/45 R16, 195/55 R16, 205/45 R17 |
| Fiat | 500X | 215/55 R17, 215/60 R16, 225/40 R19, 225/45 R18 |
| Fiat | Panda | 175/65 R14, 175/65 R15, 185/55 R15 |
| Fiat | Tipo | 195/65 R15, 205/55 R16 |
| Ford | Kuga | 225/55 R19, 225/60 R18, 225/65 R17 |
| Ford | Puma | 215/50 R18, 215/55 R17, 225/40 R19 |
| Hyundai | Tucson | 215/65 R17, 235/50 R19, 235/55 R18 |
| Hyundai | i10 | 175/65 R14, 195/45 R16 |
| Hyundai | i20 | 185/65 R15, 195/55 R16, 215/45 R17 |
| Kia | Sportage | 215/65 R17, 235/50 R19, 235/55 R18 |
| Nissan | Juke | 215/60 R17, 225/45 R19 |
| Nissan | Qashqai | 215/65 R17, 235/45 R20, 235/50 R19, 235/55 R18 |
| Opel | Astra | 205/55 R16, 215/45 R18, 225/40 R18, 225/45 R17 |
| Opel | Corsa | 195/55 R16, 205/45 R17 |
| Opel | Crossland | 195/60 R16, 205/60 R16, 215/50 R17 |
| Opel | Grandland | 225/55 R18, 225/55 R19, 235/50 R20, 235/55 R19 |
| Opel | Mokka | 195/60 R18, 215/60 R17, 215/65 R16, 225/40 R20 |
| Peugeot | 2008 | 215/55 R18, 215/60 R17, 215/65 R16 |
| Peugeot | 208 | 195/55 R16, 205/45 R17 |
| Peugeot | 3008 | 225/55 R19, 235/50 R20, 235/55 R19 |
| Peugeot | 308 | 205/55 R16, 215/45 R18, 225/40 R18, 225/45 R17 |
| Peugeot | 5008 | 215/65 R17, 225/55 R18, 225/55 R19, 225/60 R18, 235/50 R20, 235/55 R19 |
| Peugeot | Partner | 195/65 R15, 205/60 R16, 215/65 R16 |
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
| Volkswagen | T-Cross | 205/55 R17, 205/60 R16, 215/45 R18 |
| Volkswagen | T-Roc | 215/50 R18, 215/55 R17, 215/60 R16, 225/50 R18, 225/55 R17, 225/60 R16 |
| Volkswagen | Tiguan | 215/65 R17, 235/55 R18, 255/45 R19 |
| Volkswagen | Touareg | 255/55 R19, 285/40 R21 |
| Škoda | Fabia | 185/65 R15, 195/55 R16, 215/45 R17, 215/45 R18 |
| Škoda | Kamiq | 205/55 R17, 205/60 R16 |
| Škoda | Karoq | 215/50 R18, 215/55 R17, 225/50 R18 |
| Škoda | Kodiaq | 215/65 R17, 235/45 R20, 235/50 R19 |
| Škoda | Octavia | 205/55 R17, 225/45 R18 |
| Škoda | Scala | 195/60 R16, 205/50 R17, 205/55 R16 |

## 4. Motorisations des versions vendues au Maroc (guide Wandaloo)

418 motorisations pour 281 modèles de 61 marques, lues le 12 novembre 2026 sur les fiches techniques
du guide d'achat marocain Wandaloo (une fiche par version au tarif Maroc) : désignation, carburant, cylindrée,
cylindres, puissance, couple, boîte. Le guide ne publie ni code moteur, ni dimension de pneus, ni huile.
Les modèles déjà couverts par un document constructeur (section 1) ne sont pas repris.

Le guide donne aussi le diamètre des jantes de chaque version marocaine : il est rappelé dans la note de chaque
monte « marché France » (section 3), qui dit si son diamètre existe au Maroc.

| Marque | Modèles | Motorisations | Modèles couverts |
|---|---:|---:|---|
| AVATR | 3 | 3 | 07, 11, 12 |
| Abarth | 2 | 2 | 595, 695 |
| Alfa Romeo | 3 | 4 | Junior, Stelvio, Tonale |
| Audi | 12 | 17 | A1, A3, A5, A6, A6 e-tron, A8, Q2, Q3, Q5, Q6 e-tron, Q7, Q8 |
| BAIC | 4 | 6 | BJ30e, U5 PLUS, X35, X55 |
| BMW | 17 | 33 | Série 1, Série 2, Série 3, Série 4, Série 5, Série 7, X1, X2, X3, X4, X5, X6, X7, XM, Z4, i5, iX |
| BYD | 10 | 16 | Atto 2 Phev, Atto 3, Han, Seagull, Seal, Seal 5 Phev, Seal U, Sealion 5 Phev, Sealion 7, Ti 7 PHEV |
| Changan | 5 | 7 | Alsvin, CS15, CS35 Plus, CS55 Plus, Uni-K |
| Chery | 8 | 11 | Arrizo 6, Himla, Tiggo 2, Tiggo 3 X, Tiggo 4, Tiggo 7, Tiggo 8, Tiggo 9 PHEV |
| Citroën | 7 | 12 | AMI, Berlingo, C3, C3 Aircross, C4, C4 X, C5 Aircross |
| Cupra | 3 | 6 | Formentor, Leon, Terramar |
| DEEPAL | 3 | 3 | G318, S05, S07 |
| DFSK | 1 | 1 | Glory 500 |
| DS Automobiles | 3 | 4 | DS4, DS7, N°4 |
| Dacia | 2 | 4 | Sandero Streetway, Spring |
| Denza | 3 | 4 | B5, B8, Z9GT |
| Dongfeng | 4 | 5 | BOX, HUGE, MAGE, SHINE |
| EXEED | 4 | 5 | Exlantix ES, Exlantix ET, RX, VX |
| Fiat | 4 | 4 | 500X, 600, 600e, Doblo |
| Ford | 3 | 4 | Kuga, Ranger, Territory |
| GAC | 4 | 4 | Emkoo, Emzoom, GN8, S7 |
| Geely | 9 | 10 | Cityray, Coolray, EX2, Emgrand, Ex5 Phev, GX3 Pro, Geometry C, Monjaro PHEV, Riddara RD6 PHEV |
| Great Wall | 8 | 12 | Haval H6, Haval H7, Haval Jolion, Ora 03, Poer, Tank 300, Tank 500, Wey 05 |
| Honda | 4 | 4 | Accord, CR-V, HR-V, Jazz |
| Hyptec | 1 | 1 | HT |
| Hyundai | 4 | 6 | Grand i10 Sedan, Ioniq 5, Ioniq 6, i10 |
| JAC | 2 | 3 | E30X, T8 |
| JAECOO | 1 | 3 | 7 |
| Jaguar | 1 | 1 | F-Pace |
| Jeep | 3 | 4 | Avenger, Compass, Wrangler |
| Jetour | 4 | 7 | Dashing, G700, T1, T2 |
| KGM | 4 | 7 | Musso Grand, Rexton, Tivoli, Torres |
| Kia | 4 | 5 | EV3, EV5, EV6, EV9 |
| Land Rover | 4 | 9 | Range Rover, Range Rover Evoque, Range Rover Sport, Range Rover Velar |
| Leapmotor | 2 | 2 | B10, T03 |
| Lexus | 5 | 7 | ES, NX, RX, RZ, UX |
| Lynk & Co | 1 | 1 | 08 |
| MG | 5 | 7 | Cyberster, HS, MG3, MG5, ZS |
| Mahindra | 3 | 3 | Kuv 100, Pik Up, Xuv 3Xo |
| Maserati | 5 | 8 | Ghibli, GranTurismo, Grecale, Levante, MC20 |
| Mazda | 4 | 5 | 3, 6, CX-3, CX-5 |
| Mercedes-Benz | 19 | 40 | CLA, CLE Cabriolet, CLE Coupé, Classe A, Classe C, Classe E, Classe G, Classe S, EQA, EQB, EQE, EQS, Eqe Suv, Eqs Suv, GLA, GLB, GLC, GLE, GLS |
| Mini | 4 | 10 | Aceman, Cooper 3 portes, Cooper 5 portes, Countryman |
| Mitsubishi | 3 | 4 | Outlander, Outlander Sport, Pajero Sport |
| Nissan | 3 | 4 | Juke, Qashqai, X-Trail |
| OMODA | 2 | 2 | 5 Shs-H, E5 |
| Opel | 6 | 9 | Astra, Corsa, Frontera, Grandland, Mokka, Rocks-e |
| Peugeot | 10 | 12 | 2008, 208, 3008, 308, 408, 5008, 508, Partner, Rifter, e-208 |
| ROX | 1 | 1 | ADAMAS |
| Renault | 6 | 8 | 5 E-Tech, Austral, Boreal, Kangoo, Mégane, Rafale |
| SEAT | 4 | 7 | Arona, Ateca, Ibiza, Leon |
| Seres | 2 | 3 | 3, 5 |
| Soueast | 5 | 8 | S05 Ice, S06, S07, S08 DM, S09 |
| Suzuki | 2 | 3 | Jimny, Swift |
| Toyota | 10 | 12 | C-HR, Corolla, Corolla Sport, Corolla X SUV, Fortuner, Land Cruiser Prado, RAV4, Yaris, Yaris Cross, bZ4X |
| Volkswagen | 6 | 7 | Caddy, Golf, T-Cross, T-Roc, Taigo, Touareg |
| Volvo | 4 | 5 | EX30, XC40, XC60, XC90 |
| XPeng | 3 | 6 | G6, G9, P7+ |
| Zeekr | 3 | 5 | 001, 7X, X |
| iCAUR | 2 | 3 | 03T, V27 |
| Škoda | 7 | 9 | Fabia, Kamiq, Karoq, Kodiaq, Octavia, Scala, Superb |

## 5. Pneus proposés à la vente au Maroc (revendeur pneu.ma)

1117 références (marque, gamme, dimension, indices de charge et de vitesse, mentions XL / RunFlat…)
relevées le 12 novembre 2026 sur le catalogue en ligne du revendeur marocain pneu.ma, visibles dans
*Références pneus*. Les prix ne sont pas repris. Cela montre ce qui se vend au Maroc à cette date,
pas une compatibilité avec un véhicule ni un stock de l'entreprise.

| Marque | Références relevées |
|---|---:|
| BFGoodrich | 13 |
| Barum | 22 |
| Bridgestone | 105 |
| Continental | 297 |
| Dunlop | 4 |
| Falken | 16 |
| Goodyear | 105 |
| Hankook | 47 |
| Kleber | 2 |
| Laufenn | 35 |
| Michelin | 164 |
| Nexen | 80 |
| Pirelli | 225 |
| Sava | 2 |

## Ce qui reste à obtenir

| Sujet | Raison | Ce qu'il faut |
|---|---|---|
| Huile par moteur (viscosité, norme, capacité) pour presque tous les modèles | Aucune source publique lisible ne la donne pour les versions marocaines : les manuels constructeur ne sont pas en ligne ou sont protégés. Seuls Kia Picanto, Sonet et Sorento sont couverts. | Carnet d'entretien ou manuel du véhicule ; saisie dans *Compatibilités huiles* |
| Codes moteur | Ni les documents constructeur ni le guide Wandaloo ne les publient. | Carte grise |
| Huiles Shell, Castrol, Mobil, Motul, Liqui Moly… | Leurs fiches produit refusent la lecture automatique ou sont chargées dynamiquement. | Fiches techniques des fournisseurs, ou saisie dans *Références huiles* |
| Indices de charge et de vitesse par véhicule ; pneus Audi A3, MG ZS, Fiat Doblo | Non publiés par les sources lues. | Étiquette de portière |
| Marques de pneus non vendues par pneu.ma (Kumho, Yokohama, Toyo, marques chinoises…) | Un seul revendeur a pu être lu. | Liste des fournisseurs de l'entreprise |
| Nissan Patrol | La brochure en ligne est celle d'un marché à conduite à droite. | Fiche Nissan Maroc |

## Règles

- Les lignes déjà présentes ne sont jamais écrasées : notes du responsable, corrections, compatibilités
  vérifiées ou obsolètes. Seuls les champs vides d'une gamme ou d'une motorisation sont complétés.
- Aucun produit, prix, SKU ou stock n'est créé.
- Pour ajouter des données : nouvelles lignes dans le fichier JSON (mêmes colonnes que le classeur V1.3),
  chacune avec sa source ; les tests `MoroccoVehicleReferenceTest` refusent une ligne sans source.
