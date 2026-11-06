# PNEU MOUHIM Manager – Cahier des charges

**Version :** 1.2 – 6 novembre 2026 (ajouts : §10.1, §11, §12, §14, §16.1, §20 ; décisions D-48 à D-53)
**Activité :** pneumatiques neufs et d'occasion, jantes, huiles, filtres, batteries, éclairage et services automobiles liés
**Pays :** Maroc – **Devise :** MAD – **Langues :** français / arabe (LTR et RTL)
**Supports :** PC, tablette, smartphone
**Sites :** Dépôt Central, Magasin 01 (vente + atelier), Magasin 02 (vente + atelier)

Ce document décrit le système cible. Il est réalisé **par phases** (section 20). Les décisions de `docs/decisions.md` priment.

---

## 1. Objectif

Donner au propriétaire une vision et un contrôle complets sur toute l'entreprise et chacun de ses sites : ventes, devis, factures, achats, stocks, transferts, retours et échanges, crédits clients, caisse, dépenses, ateliers, maintenance des équipements, clients et flottes.

Permettre aux vendeurs de vendre vite et sans erreur, et aux techniciens de suivre leurs travaux simplement.

Les problèmes prioritaires à résoudre : **stock, crédit, factures et devis, ventes**.

---

## 2. Sites

| Code | Site | Rôle |
|---|---|---|
| DEP | Dépôt Central | Stock principal, réception des achats, approvisionnement des magasins |
| M01 | Magasin 01 | Vente, atelier, stock local |
| M02 | Magasin 02 | Vente, atelier, stock local |

Règles :

- Chaque produit a un stock par site ; le stock global est la somme.
- Un transfert n'est pas une vente : il a un document, un historique et les statuts En transit / Reçu / Annulé.
- Un magasin peut émettre une **demande de réapprovisionnement** ; le dépôt la transforme en transfert.
- Les ventes, la caisse et les ordres de travail appartiennent à un site.
- Le Dépôt Central peut aussi vendre (vente en gros, flottes) si le propriétaire l'active.
- Le propriétaire voit tous les sites ; les autres utilisateurs voient leur(s) site(s) affecté(s).

---

## 3. Utilisateurs, rôles et permissions

Rôles par défaut (les permissions restent configurables par le propriétaire) :

### Propriétaire / Administrateur
Accès complet à tous les sites et modules, paramètres, utilisateurs, prix, marges, approbations, rapports, journal d'activité.

### Responsable de site (optionnel)
Comme le vendeur, plus : clôture de caisse, réception des transferts, inventaires de son site, suivi de l'atelier. Pas d'accès aux marges globales ni aux paramètres.

### Vendeur / Caissier
Recherche produits et véhicules, devis, ventes, ajout de services, encaissements autorisés, création de clients, impression et envoi des documents.
Interdits sans approbation : modifier un prix, remise au-delà de son plafond, vente à crédit au-delà du plafond client, vente sous le coût, annulation. Jamais : voir les prix d'achat et marges, modifier un stock, supprimer un document.

### Magasinier (dépôt)
Réceptions fournisseurs, préparation et envoi des transferts, inventaires, consultation du stock. Pas d'accès aux ventes ni aux finances.

### Technicien
Écran atelier uniquement : ses travaux, véhicules, prestations, produits nécessaires, changement de statut, observations, photos, signalement de produit manquant ou de problème. Aucun accès aux prix d'achat, marges, crédits, caisse.

Toutes les permissions sont vérifiées côté serveur (Policies Laravel).

---

## 4. Approbations

Module central utilisé par tous les autres modules.

Opérations soumises à approbation (configurables) :

- Remise supérieure au plafond du rôle.
- Prix de vente modifié ou inférieur au coût.
- Vente à crédit dépassant le plafond du client, ou client en retard de paiement.
- Ajustement de stock, déclaration de produit endommagé.
- Annulation d'une vente, création d'un avoir.
- Modification d'une journée clôturée.
- Écart de caisse supérieur à un seuil.

Fonctionnement :

1. L'utilisateur fait la demande avec un motif.
2. Le propriétaire (ou un approbateur désigné) reçoit une notification sur son téléphone.
3. Il approuve ou refuse en un clic, avec commentaire facultatif.
4. L'opération continue ou est bloquée ; tout est journalisé.

Alternative sur place : saisie d'un code PIN personnel du propriétaire sur l'écran du vendeur.

---

## 5. Catalogue des produits

Catalogue centralisé, administrable, extensible. Aucune marque ni modèle codé en dur. Les données de référence (marques, catégories) sont dans des tables modifiables.

### 5.1 Catégories

Catégories hiérarchiques configurables. Catégories initiales :

- Pneus neufs (tourisme, SUV / 4x4, utilitaire, poids lourd, moto)
- Pneus d'occasion
- Jantes et enjoliveurs
- Valves, chambres à air, consommables pneus (masses d'équilibrage, mèches, rustines)
- Huiles moteur, huiles de boîte / transmission, liquides (refroidissement, frein, lave-glace), additifs
- Filtres (huile, air, habitacle, carburant)
- Batteries
- Éclairage (ampoules, phares, feux)
- Balais d'essuie-glace
- Freinage (plaquettes, disques) – si activé
- Accessoires et produits d'entretien
- Autres

### 5.2 Champs communs à tous les produits

Nom, catégorie, marque, référence fabricant, références alternatives, SKU interne (généré automatiquement), unité (pièce, bidon, kit…), TVA (20 %), prix d'achat de référence, CUMP calculé, niveau de prix (particulier / professionnel / revendeur), seuil minimum par site, fournisseur(s), photos, description, actif / inactif.

Seuls sont obligatoires : nom (ou dimension), catégorie, prix de vente. Les autres champs sont dans « Plus de détails ».

### 5.3 Pneus neufs

Champs spécifiques : largeur, série (ratio), diamètre, indice de charge, indice de vitesse, saison (été / hiver / toutes saisons), XL, Run Flat, renforcé (C), usage (tourisme, SUV, utilitaire…), modèle commercial, homologation constructeur, pays d'origine, garantie.

- Dimension normalisée en champs séparés (largeur, série, diamètre) pour la recherche.
- Gestion par **lots** : quantité, date de réception, fournisseur, prix d'achat, DOT (semaine/année), site.
- Alerte sur l'âge des pneus en stock (seuil configurable, ex. DOT > 3 ans).

### 5.4 Pneus d'occasion

Suivi **individuel** (une fiche par pneu) :

- Identifiant unique + étiquette QR imprimable.
- Marque, modèle, dimension, indices si lisibles, DOT si lisible.
- Profondeur de sculpture (mm).
- Origine : achat fournisseur, reprise client (lien vers la vente), autre.
- Prix d'achat ou valeur de reprise, prix de vente.
- Photos (prises directement avec le téléphone).
- Site et emplacement.

**Contrôle de sécurité obligatoire** avant mise en vente (formulaire rapide oui / non) : flancs, fissures, hernies, déformations, talon, réparations antérieures, usure irrégulière, profondeur, âge.
Un seul point dangereux = statut « Non vendable ».

Statuts : En attente de contrôle, En stock, Réservé, Vendu, Non vendable, Mis au rebut.

La recherche propose les **jeux assortis** (2 ou 4 pneus de même dimension, même marque / modèle, usure proche).

### 5.5 Huiles et liquides

Viscosité (0W20, 5W30, 10W40…), normes ACEA / API, homologations constructeur, type (synthèse, semi-synthèse, minérale), contenance (1L, 2L, 4L, 5L…).
Vente **par bidon fermé uniquement** : pas de vente au litre ni de stock en fût.

### 5.6 Filtres

Type, marque, référence fabricant, références équivalentes (cross-références), véhicules compatibles (section 7).

### 5.7 Batteries

Tension, capacité (Ah), CCA, dimensions, polarité, technologie (standard, EFB, AGM), garantie, date d'entrée.
Gestion de la **reprise de l'ancienne batterie** (consigne / valeur déduite) et suivi de la garantie par numéro de série.

### 5.8 Éclairage

Type de culot (H1, H4, H7, H11, LED…), puissance, tension, usage (feu de croisement, route, antibrouillard, clignotant…).

### 5.9 Services (prestations)

Administrables : nom, catégorie, prix (fixe, ou variable selon la dimension du pneu par exemple montage 13-15" / 16-17" / 18"+), TVA, durée indicative, sites concernés, actif / inactif.

Services initiaux : montage / démontage, équilibrage, parallélisme, réparation crevaison, réparation jante, gonflage azote, permutation, vidange, remplacement filtres, test et remplacement batterie, réglage et remplacement éclairage, freinage (si activé), autres.

**Forfaits** : combinaison produits + services à prix global (ex. « 4 pneus + montage + équilibrage + valves », « Vidange complète »). Le forfait se décompose automatiquement sur la facture et dans les mouvements de stock.

**Consommables automatiques** : un service peut consommer automatiquement des articles (ex. équilibrage → masses ; montage → valve), configurable.

---

## 6. Stock

### 6.1 Principe
Le registre des mouvements est la source de vérité. Aucune modification directe des quantités.

Types de mouvements : réception achat, vente, retour client, échange client, retour fournisseur, transfert sortant, transfert entrant, consommation atelier, ajustement d'inventaire (approuvé), produit endommagé (approuvé), reprise client, stock d'ouverture.

Chaque mouvement : produit, site, quantité (+/-), coût unitaire, auteur, date, motif, document lié.

### 6.2 Vues
- Stock par site, stock global, stock réservé, stock disponible.
- Valeur du stock (au CUMP) par site et globale.
- Produits sous le seuil minimum, en rupture, à faible rotation (aucune sortie depuis X jours), pneus trop anciens.
- Historique complet d'un produit (fiche de stock).

### 6.3 Transferts
- Création depuis le dépôt ou depuis une demande de réapprovisionnement d'un magasin.
- **Suggestion automatique de réapprovisionnement** : produits sous le seuil dans un magasin et disponibles au dépôt.
- Envoi (sortie du stock source) → réception (entrée au destinataire), avec saisie des quantités reçues et motif en cas d'écart.
- Bon de transfert imprimable.

### 6.4 Inventaire
- Inventaire complet ou partiel (par catégorie, par emplacement) par site.
- Saisie des quantités comptées sur tablette / téléphone.
- Rapport d'écarts, validation par le propriétaire, génération des ajustements.

### 6.5 Retours et échanges
- Retour client : avoir ou remboursement, remise en stock ou déclaration « endommagé ».
- Échange client : retour d'un article + vente d'un autre dans une seule opération, différence à payer ou à rembourser.
- Retour fournisseur : sortie de stock, réduction de la dette fournisseur.

---

## 7. Véhicules et compatibilité

### 7.1 Référentiel véhicules
Tables modifiables : marques, modèles, (génération / années, motorisation, carburant facultatifs). Un référentiel initial des marques et modèles courants au Maroc peut être fourni comme **données de référence** modifiables.

### 7.2 Véhicules des clients
Un client peut avoir plusieurs véhicules : immatriculation (format marocain + WW + étranger, recherche tolérante aux tirets et espaces), marque, modèle, année, motorisation, kilométrage, dimensions de pneus avant / arrière, notes.

Historique complet par véhicule : pneus, huiles, filtres, batteries, prestations, kilométrage à chaque passage, factures.

### 7.3 Compatibilités vérifiées
Associations modèle / motorisation / années ↔ produit ou spécification : dimensions de pneus (avant / arrière, indices), viscosité et normes d'huile, références de filtres, caractéristiques de batterie, culots d'ampoules, pièces de freinage si activé.

Trois sources d'alimentation :
1. **Données vérifiées fournies par l'entreprise** (chargement initial).
2. **Saisie manuelle** par un utilisateur autorisé (permission dédiée).
3. **Import Excel** avec modèle téléchargeable, contrôle, aperçu et rapport d'erreurs (section 17).

Chaque compatibilité porte : source, date, auteur, statut (Vérifié, Non vérifié, À contrôler, Obsolète), notes.
- Seules les compatibilités au statut **Vérifié** sont affichées comme compatibles dans l'écran de vente.
- Les autres sont visibles uniquement par les utilisateurs autorisés, clairement marquées.
- Toute modification est journalisée.

### 7.4 Historique d'utilisation (information)
Le système affiche séparément ce qui a déjà été monté :
- **sur ce véhicule client** : « Dernière vidange : huile X 5W30, filtre réf. Y – 12/09/2026, 84 000 km » ;
- **sur ce modèle chez nous** : « Déjà monté sur Peugeot 208 : 195/55 R16 (14 fois) ».

Cet historique est une information d'aide, jamais présenté comme une compatibilité vérifiée. Un utilisateur autorisé peut, après vérification, le transformer en compatibilité vérifiée.

Règles :
- Jamais de compatibilité inventée ou générée automatiquement.
- Compatibilité indépendante du stock : afficher la disponibilité par site à côté.

---

## 8. Recherche

Barre de recherche unique, présente partout, rapide (objectif < 300 ms).

Comprend : dimension sous toutes ses formes (`205 55 16`, `2055516`, `205/55R16`, `205/55 R16 91V`), immatriculation, nom ou téléphone client, référence, SKU, nom de produit, viscosité (`5w30`), culot (`H7`), numéro de document.

Résultats groupés (produits, clients, véhicules, documents) avec stock par site et prix selon le rôle.
Filtres rapides : site, catégorie, marque, en stock seulement, neuf / occasion.

---

## 9. Clients

### Particuliers
Nom, téléphone (obligatoire), autres champs facultatifs.

### Professionnels / flottes
Raison sociale, ICE, IF, RC, adresse, contacts, téléphone, email, niveau de prix ou grille spécifique, plafond de crédit, délai de paiement, numéro de bon de commande exigé (oui / non), mode de facturation (immédiate ou récapitulative).

### Fonctions
- Fiche client : véhicules, historique, documents, solde, retards, chiffre d'affaires.
- **Flottes** : rapport par véhicule (dépenses, pneus montés, kilométrage), envoyable au client.
- Fusion de doublons (propriétaire seulement).

---

## 10. Devis, ventes et documents

### 10.1 Écran de vente (Livewire dédié)
Un seul écran :
1. Site (par défaut celui de l'utilisateur).
2. Client et véhicule (recherche par immatriculation ou téléphone ; **création rapide : téléphone + nom seulement**, le reste facultatif).
3. Ajout de produits, services, forfaits (un bouton par forfait) ; propositions issues de l'historique du véhicule et des **compatibilités vérifiées** (§7.3), avec le stock de chaque site.
   - Produit absent du site mais disponible au dépôt : bouton **« Demander au dépôt »** qui crée la demande de réapprovisionnement.
4. **Reprise** : pneus / batterie laissés par le client → déduction sur le total et création des fiches occasion « en attente de contrôle ».
5. Remise (dans la limite du rôle, sinon demande d'approbation).
6. Paiement : espèces par défaut, carte, chèque (numéro, banque, tireur, échéance), plusieurs moyens, paiement partiel, reste à crédit (D-50).
7. Validation → document, sortie de stock, ordre de travail si services (techniciens affectés par le vendeur, D-53), encaissement.
8. Impression **A4 uniquement** (D-51) et envoi WhatsApp en un clic (lien `wa.me` avec message pré-rempli et lien du document).

Statuts d'une vente : Brouillon, Validée, Annulée (par avoir).

### 10.2 Documents
| Document | Usage |
|---|---|
| Devis | Proposition de prix, validité configurable, conversion en vente en un clic |
| Bon de livraison / Bon de travail | Livraison ou prestation pour professionnel, signé par le chauffeur / client |
| Facture | Immédiate ou récapitulative (regroupe plusieurs BL / BT d'une période) |
| Avoir | Annulation totale ou partielle d'une facture |
| Reçu de paiement | Pour chaque encaissement |
| Relevé de compte | Situation d'un client sur une période |
| Bon de transfert | Mouvement entre sites |
| Fiche d'intervention | Remise au client : travaux faits, km, prochaine vidange conseillée |

### 10.3 Devis
- Création rapide depuis l'écran de vente (même écran, bouton « Devis »).
- Statuts : Brouillon, Envoyé, Accepté, Refusé, Expiré, Converti.
- Duplication d'un devis, modèles de devis fréquents.
- Liste des devis à relancer ; taux de conversion par vendeur.
- Réservation de stock facultative sur devis accepté.

### 10.4 Mentions et mise en page
- En-tête configurable : logo, raison sociale, adresse, ICE, IF, RC, Patente (TP), CNSS, téléphone, RIB.
- Détail HT / TVA 20 % / TTC, montant TTC **en toutes lettres** (« Arrêtée la présente facture à la somme de… »).
- N° de bon de commande client si fourni.
- Français par défaut ; arabe possible.
- Toutes les mentions légales, le droit de timbre éventuel et la numérotation sont **validés par le comptable** avant production.

---

## 11. Crédit clients et encaissements

- Compte client : factures (débit), paiements et avoirs (crédit), solde d'ouverture.
- Paiement réparti sur plusieurs factures (affectation automatique aux plus anciennes, modifiable).
- Plafond de crédit et délai de paiement par client ; blocage + approbation en cas de dépassement ou de retard.
- **Balance âgée** : 0–30, 31–60, 61–90, > 90 jours.
- Relevé de compte PDF + envoi WhatsApp.
- Relance : message pré-rempli (FR / AR) avec le solde et les factures dues.
- **Page « Ce qui reste »** (propriétaire, mobile) : créances par client et ancienneté (relance WhatsApp en un clic), chèques / LCN non encaissés, devis non convertis, véhicules encore à l'atelier, transferts en transit, demandes de réapprovisionnement en attente.

Moyens de paiement : espèces, carte, virement, chèque, LCN / effet, autres configurables.

Chèques et LCN : numéro, banque, tireur, montant, échéance, statut (Reçu, Garantie, Remis en banque, Encaissé, Rejeté).
- Échéancier et alertes (à encaisser aujourd'hui / cette semaine).
- Rejet → dette rétablie, historique conservé, frais éventuels ajoutés.

Les délais de paiement entre professionnels doivent respecter la loi marocaine en vigueur (à confirmer par le comptable) ; le système permet d'afficher les factures fournisseurs et clients dépassant le délai.

---

## 12. Caisse et dépenses

Caisse par site :
- Ouverture (fond de caisse), encaissements automatiques depuis les ventes et règlements, dépenses, retraits / versements vers le propriétaire ou la banque.
- Clôture : montant attendu vs compté par moyen de paiement, écart avec motif, approbation au-delà d'un seuil.
- Après clôture, la journée est verrouillée (D-29).
- Rapport journalier imprimable.

Dépenses : catégories configurables (loyer, électricité, eau, carburant, transport, fournitures, maintenance, divers), site, **justificatif photo pris avec le téléphone**, auteur. Les dépenses ne sont jamais confondues avec les achats de marchandises.

---

## 13. Fournisseurs et achats

- Fiche fournisseur : coordonnées, ICE / IF, conditions, produits fournis, historique des prix.
- Bon de commande (facultatif) → réception (totale ou partielle) au site choisi (dépôt par défaut) → saisie des coûts, lots et DOT → mise à jour du CUMP → dette fournisseur.
- Facture fournisseur, paiements, solde, échéances.
- **Comparaison des prix** d'un produit entre fournisseurs, alerte de hausse de coût et suggestion de révision du prix de vente.
- **Suggestion d'achat** : produits sous le seuil au niveau global, basée sur les ventes récentes.

---

## 14. Atelier – ordres de travail

- Une vente (ou un BT) contenant des services génère un ordre de travail.
- Contenu : numéro, site, client, véhicule, kilométrage, prestations, produits à utiliser, technicien(s), heures début / fin, observations, photos avant / après, statut.
- Statuts : En attente, En cours, En pause, Terminé, Livré, Annulé (utilisateur autorisé).
- **Saisie par le vendeur (D-53)** : le technicien n'a pas à utiliser de téléphone ni d'ordinateur. Le vendeur affecte les techniciens (gros boutons avec leurs noms), fait avancer les statuts et saisit les observations.
- **Écran technicien** (facultatif) : mobile, arabe par défaut, gros boutons. Il affiche les travaux du technicien et permet de démarrer / mettre en pause / terminer, de signaler un produit manquant, d'ajouter une observation ou une photo, et de signaler un problème constaté sur le véhicule (proposé ensuite au client par le vendeur sous forme de devis).
- **Tableau atelier** (écran TV ou tablette) : véhicules par statut, mise à jour en temps réel.
- Temps passé et nombre d'interventions par technicien (rapport).
- Prochaine vidange (km / date) enregistrée sur la fiche véhicule.

Hors V1 : paie, planning RH.

---

## 15. Équipements et maintenance

Module distinct du catalogue commercial.

Fiche équipement : identifiant, nom, catégorie (démonte-pneu, équilibreuse, banc de parallélisme, compresseur, générateur d'azote, pont élévateur, cric, clé à choc, matériel de vidange, réglophare, autres), marque, modèle, n° de série, site, date et prix d'achat, fournisseur, garantie, état, photos, documents.

Maintenance : plan préventif (périodicité), historique (entretien, panne, réparation, pièces, coût, intervenant), signalement de panne par un technicien, alertes de maintenance à venir, coût total par équipement.

---

## 16. Tableau de bord et rapports

### 16.1 Tableau de bord du propriétaire

**Page « Aujourd'hui » (téléphone d'abord)**, première page du propriétaire :
- Par site et au total : chiffre d'affaires du jour, encaissements par moyen (espèces, carte, chèques), ventes à crédit, dépenses, marge (signalée incomplète si des coûts manquent), comparaison avec la veille et le même mois précédent.
- **Objectifs mensuels par site** fixés par le propriétaire, avec une barre de progression.
- Fil « en direct » des dernières opérations (ventes, clôtures, transferts), filtrable par site.
- Application installable sur l'écran d'accueil (PWA) avec **notifications push** (approbations, écarts de caisse, résumé du soir).

En haut, les **alertes à traiter** (3 à 5 maximum) : approbations en attente, clients en retard importants, chèques / LCN à échéance, ruptures dans un magasin avec stock disponible au dépôt, écarts de caisse.

Ensuite, filtrables par période et par site (ou tous) :
- Chiffre d'affaires, nombre de ventes, panier moyen.
- Marge brute (signalée comme incomplète si des coûts manquent).
- Encaissements, crédit accordé, total des créances, dettes fournisseurs.
- Valeur du stock, ruptures, produits dormants.
- Activité atelier.
- Comparaison des trois sites.

### 16.2 Résumé quotidien
Chaque soir (heure configurable, 20 h par défaut), résumé automatique au propriétaire (notification push + email en V1) : CA par site, encaissements, crédits accordés, règlements reçus, écarts de caisse, ruptures, approbations effectuées.

### 16.3 Rapports
Ventes (par site, vendeur, catégorie, marque, client, période), produits les plus vendus, marges par produit / catégorie / service, stock et valorisation, mouvements, transferts, balance âgée, chèques / LCN, caisse, dépenses, achats et fournisseurs, atelier et techniciens, devis et conversion, équipements.
Export Excel et PDF ; **export pour le comptable** (ventes, TVA, achats, encaissements).

### 16.4 Journal d'activité
Écran propriétaire listant toutes les opérations (qui, quoi, quand, quel site, avant / après), filtrable par site, utilisateur, type et date. Non modifiable.

---

## 17. Importation et démarrage

Import XLSX avec modèles téléchargeables : clients, fournisseurs, produits (par catégorie), pneus d'occasion, services, véhicules clients, compatibilités, **stock d'ouverture par site**, **soldes d'ouverture clients et fournisseurs**.

Processus : télécharger le modèle → remplir → importer → contrôle des colonnes → liste des erreurs ligne par ligne → aperçu → confirmation → rapport d'import.
Détection des doublons (SKU, référence, téléphone, ICE) avec choix : ignorer, mettre à jour, créer.

Procédure de démarrage documentée : paramètres société → sites → utilisateurs → import catalogue → inventaire d'ouverture → soldes d'ouverture → mise en service.

---

## 18. Notifications et communication

- Notifications internes (cloche) et notifications push sur téléphone (PWA) pour le propriétaire et les responsables.
- WhatsApp via liens `wa.me` avec messages pré-remplis FR / AR : envoi de facture, devis, relevé, relance, « votre véhicule est prêt ».
- Email pour le résumé quotidien et l'envoi de documents.

---

## 19. Exigences non fonctionnelles

- **Rapidité** : recherche < 300 ms, pages < 2 s sur connexion 4G, pagination et index partout.
- **Responsive** : PC, tablette, téléphone ; application installable (PWA).
- **Langues** : FR / AR par utilisateur, RTL complet, documents en français par défaut.
- **Sécurité** : rôles et Policies, limitation des tentatives de connexion, sessions sécurisées, double authentification facultative pour le propriétaire, aucune donnée sensible dans le dépôt.
- **Sauvegardes** : quotidiennes, automatiques, stockage externe à Railway, conservation 30 jours minimum, restauration testée et documentée.
- **Fiabilité** : transactions et verrous sur toutes les opérations financières et de stock ; commande de vérification de cohérence du stock.
- **Traçabilité** : journal d'activité sur toutes les opérations sensibles.
- **Données de démonstration** : uniquement dans `DemoSeeder`, jamais en production.

---

## 20. Phases de réalisation

Chaque phase se termine par des tests verts, une démonstration et la mise à jour de la documentation.

| Phase | Contenu | Critère de validation |
|---|---|---|
| 1. Fondations | Docker, Laravel, Filament, PostgreSQL, authentification, rôles et permissions, sites, langues FR / AR, journal d'activité, paramètres société, sauvegardes, déploiement Railway | Connexion par rôle, interface FR / AR, déploiement fonctionnel, sauvegarde restaurée avec succès |
| 2. Catalogue et stock | Catégories, produits, pneus neufs (lots, DOT), pneus d'occasion (contrôle, QR), services, forfaits, mouvements, transferts, réapprovisionnement, inventaire, approbations (socle) | Scénarios de stock multi-sites (voir 21) |
| 3. Ventes et documents | Clients (création rapide), véhicules, recherche unique, écran de vente, « Demander au dépôt », reprises (plafond vendeur), devis, BL / BT, factures, factures récapitulatives, avoirs, retours, échanges, PDF A4, WhatsApp, paiement à la vente (espèces, carte, chèque, crédit), numérotation paramétrable, ordres de travail saisis par le vendeur | Vente complète < 1 minute, documents conformes |
| 4. Argent | Comptes clients, paiements, affectation, crédit et plafonds, chèques / LCN, balance âgée, relevés, caisse par site, clôture, verrouillage, dépenses avec photo, page « Ce qui reste » | Scénarios de crédit et de caisse (voir 21) |
| 5. Démarrage | Imports Excel, soldes et stocks d'ouverture, page « Aujourd'hui » sur mobile, objectifs par site, résumé du soir, PWA et notifications push, `DemoSeeder` | **Mise à l'essai chez le client** |
| 6. Achats et atelier | Fournisseurs, commandes, réceptions, dettes, comparaison des prix, écran technicien, tableau atelier | Cycle achat → stock → paiement complet |
| 7. Compléments | Compatibilités vérifiées (saisie manuelle, import Excel, données fournies), historique d'utilisation, rapports avancés, rapports flottes, équipements et maintenance, exports comptables, garanties | Rapports validés par le propriétaire |

---

## 21. Scénarios de test obligatoires (exemples)

**Stock multi-sites**
1. Réception de 100 pneus 205/55 R16 au Dépôt.
2. Transfert de 20 vers M01 et 15 vers M02, réceptionnés.
3. Vérifier : DEP 65, M01 20, M02 15, global 100.
4. Vente de 4 pneus à M01 → M01 16, autres sites inchangés.
5. Vérifier que chaque mouvement existe dans le registre et que `stock:verify` ne trouve aucun écart.
6. Tentative de vente de 30 pneus à M02 → refusée.

**Crédit**
1. Client pro, plafond 10 000 MAD, solde d'ouverture 3 000 MAD.
2. Facture de 6 000 MAD à crédit → acceptée, solde 9 000.
3. Facture de 2 000 MAD à crédit → bloquée, demande d'approbation.
4. Paiement par chèque de 5 000 MAD réparti sur deux factures.
5. Chèque rejeté → solde rétabli, historique intact.

**Documents**
1. Facture validée : suppression et modification impossibles.
2. Avoir partiel : solde client et stock corrigés.
3. Numérotation continue même en cas de ventes simultanées sur deux sites.

**Permissions**
1. Un vendeur ne voit ni prix d'achat ni marge (interface et API).
2. Un technicien ne peut accéder à aucune page de vente ou de caisse, même par URL directe.
3. Un vendeur de M01 ne voit pas la caisse de M02.

**Pneus d'occasion**
1. Pneu avec hernie au contrôle → non vendable, absent des résultats de vente.
2. Reprise de 4 pneus lors d'une vente → 4 fiches « en attente de contrôle », déduction sur la facture.

---

## 22. Points à valider avec le comptable avant production

Ces points sont des paramètres configurables (D-30) en attendant la validation : numérotation des documents, mentions obligatoires sur factures et avoirs, droit de timbre sur paiements en espèces, traitement de la TVA sur les pneus d'occasion et les reprises, délais de paiement légaux, méthode de valorisation du stock (CUMP), format des exports comptables.
