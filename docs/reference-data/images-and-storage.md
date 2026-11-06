# Images du catalogue et stockage

## Champs ajoutés

- brands.logo_path : logo d'une marque commerciale (pneus, huiles, pièces).
- vehicle_makes.logo_path : logo d'un constructeur automobile.
- vehicle_models.image_path : photo illustrative du modèle.
- products.image_path : photo du produit réellement vendu (référence commerciale / emballage).

Les images sont facultatives. Une absence d'image ne bloque jamais la création ni l'import d'une fiche.

## Interface

Les formulaires Filament permettent de téléverser une image JPG, PNG ou WebP, de la recadrer et de la remplacer. Les listes affichent une vignette. Les logos sont rangés sous catalog/brand-logos et catalog/vehicle-makes, les photos sous catalog/vehicle-models et catalog/products.

La première version utilise le disque Laravel public. Les URL externes ne sont pas intégrées directement dans l'interface : on téléverse une copie autorisée, ce qui évite les images cassées, les liens tiers instables et le suivi par des hébergeurs externes.

## Règles de gestion

- Logo de constructeur ≠ photo du modèle ≠ photo du produit vendu.
- Ne pas mettre le logo Michelin comme photo d'un pneu commercial précis.
- Les photos génériques illustratives ne prouvent aucune compatibilité technique.
- Privilégier les images officielles autorisées ou les photos prises par PNEU MOUHIM.
- Conserver une image originale de bonne qualité ; l'interface affiche une vignette carrée.
- N'accepter que JPEG, PNG et WebP, avec limite de taille.

## Railway : condition préalable à la mise en production

Le disque public pointe vers storage/app/public. Sur Railway, ce chemin doit être couvert par un volume persistant monté sur le chemin effectif utilisé par l'application (/app/storage/app/public dans la configuration connue du projet). Avant l'activation en production :

1. Vérifier que le volume appartient au service web/app et non uniquement au service PostgreSQL.
2. Monter le volume au chemin exact du disque public.
3. Créer / vérifier le lien public/storage si la stratégie de déploiement l'utilise.
4. Téléverser une image de test, vérifier son affichage, redéployer, redémarrer le service et vérifier que l'image existe toujours.
5. Tester une sauvegarde et une restauration des fichiers, pas seulement de la base PostgreSQL.

Aucun changement Railway ni déploiement n'est effectué par cette branche.

## Tests

Le test de persistance vérifie que les chemins d'image sont enregistrés sur les quatre types de fiches sans créer de mouvement ou de quantité de stock. Les tests d'interface et le test réel de stockage Railway restent à exécuter dans un environnement de développement/CI avant fusion.
