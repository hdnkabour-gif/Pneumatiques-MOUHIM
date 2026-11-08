# PNEU MOUHIM — audit du classeur de référence V1.3

Audit effectué à partir de l'export brut de toutes les feuilles XLSX (7 novembre 2026). Ce rapport ne modifie ni le classeur source ni la base de données.

## Résultat

- **25 feuilles** exportées.
- **578 enregistrements** dans les feuilles de catalogue, compatibilité, sources et priorités (hors documentation et lignes de modèles vierges).
- **0 doublon de clé détecté** dans les identifiants et clés naturelles contrôlés.
- **0 référence étrangère cassée détectée** entre sources, générations, moteurs et compatibilités.
- **18 avertissements** de couverture ou de normalisation.
- Les feuilles PRODUCTS_IMPORT, STOCK_OPENING, USED_TYRES_IMPORT et VEHICLE_FITMENTS ne contiennent aucune donnée métier réelle : ce sont des modèles vides. Les 999 lignes de PRODUCTS_IMPORT sont un gabarit, pas 999 produits.

## Volumétrie

| Feuille | Enregistrements |
|---|---:|
| CAR_BRANDS | 39 |
| CAR_MODELS | 263 |
| TIRE_BRANDS | 36 |
| TIRE_MODELS | 34 |
| TIRE_SIZES | 50 |
| OIL_BRANDS | 22 |
| OIL_MODELS | 24 |
| STOCK_LOCATIONS | 3 |
| CAR_GENERATIONS | 10 |
| CAR_ENGINES | 17 |
| VEHICLE_TIRE_FITMENTS | 13 |
| VEHICLE_OIL_FITMENTS | 11 |
| TECH_SOURCES | 26 |
| MARKET_PRIORITY_2026 | 20 |
| RENTAL_PRIORITY_2026 | 10 |

## Anomalies et compléments nécessaires

1. **Documentation périmée :** DATA_QUALITY annonce 261 modèles, alors que CAR_MODELS contient 263. Mettre à jour le nombre dans la documentation.
2. **Modèle de priorité manquant :** Skoda Kodiaq apparaît dans MARKET_PRIORITY_2026, mais n'existe pas comme modèle autonome dans CAR_MODELS. Ajouter uniquement le nom du modèle comme candidat « À compléter », sans génération, moteur ou compatibilité inventés.
3. **Alias de génération :** Opel Corsa F est mentionnée dans les priorités tandis que CAR_MODELS contient la famille Opel Corsa. Il faut relier Corsa F comme génération après validation, pas créer un deuxième modèle Corsa.
4. **Libellés composites :** « Grand i10 / i10 » et « C3 / 500 » dans RENTAL_PRIORITY_2026 mélangent plusieurs modèles/marques. Les séparer en lignes distinctes après confirmation des véhicules réellement concernés. Grand i10 existe déjà dans CAR_MODELS; la ligne composite ne prouve pas que i10 est le même modèle.
5. **263/263 modèles sans années de début/fin, carrosserie, motorisations, génération, code moteur, carburant, boîte, puissance, cylindrée, source technique ou date de vérification dans CAR_MODELS.** Cette feuille est une liste de familles, pas un référentiel technique de véhicules.
6. **50/50 dimensions sans indices de charge ni vitesse; 50/50 sans indication RunFlat; 45/50 sans XL/Renforcé.** Les dimensions seules ne doivent pas être utilisées comme validation d'un montage.
7. **24/24 gammes d'huile sans ACEA, API, homologation OEM, référence article ni fiche technique dans OIL_MODELS.** La gamme et la viscosité indicative ne suffisent pas pour établir une compatibilité moteur.
8. **10/10 générations sans code génération et plage de production.** L'année de consultation d'une brochure ne doit pas être interprétée comme une année de fabrication.
9. **17/17 motorisations sans code moteur ni années de production.** Certaines cylindrées, puissances et couples sont aussi absents.
10. **Compatibilités incomplètes :** 6/13 fitments pneus sans Engine_ID; 6/13 sans indice de charge; 6/13 sans indice de vitesse; 12/13 sans valeur XL et 13/13 sans RunFlat renseigné. Pour les huiles, 3/11 sans Engine_ID, 3/11 sans viscosité, 10/11 sans ACEA, 11/11 sans API, 4/11 sans homologation OEM, 10/11 sans capacité, 11/11 sans référence de filtre et 3/11 sans source.
11. **Castrol EDGE apparaît deux fois** dans OIL_MODELS, mais les deux lignes correspondent à des viscosités différentes (5W-30 et 5W-40). Ce n'est pas un doublon à supprimer; la clé de comparaison doit inclure la viscosité et la référence produit.

## Décisions sûres

- Conserver les données partielles, mais les garder en statut non vérifié.
- Ne pas extrapoler les indices de charge/vitesse, les codes moteur, les années ou les homologations.
- Ne jamais considérer les statistiques de vente/location comme preuve de compatibilité technique.
- Garder distincts le catalogue de référence, les références commerciales SKU, les prix, les fournisseurs, le DOT et les quantités par dépôt.
- Les données d'huile Renault provenant d'un catalogue d'un autre marché restent limitées à ce périmètre et ne doivent pas devenir « Vérifié Maroc ».
- Aucune ligne de stock, aucun prix et aucun produit réel ne doivent être générés à partir des gabarits vides.

## Limites

Cet audit valide la structure, les doublons simples et les liens d'identifiants internes. Il ne certifie pas l'exactitude mécanique des spécifications publiées par les sources externes. La validation finale exige le VIN, la carte grise ou le manuel constructeur correspondant au marché et à la motorisation.
