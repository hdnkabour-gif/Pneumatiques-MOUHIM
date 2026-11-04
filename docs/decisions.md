# Décisions du projet – PNEU MOUHIM Manager

**Version 1.1 – 4 novembre 2026** (modifications : D-06, D-19, D-24, D-30)

Ces décisions sont validées. Toute modification nécessite l'accord explicite du responsable du projet.
Format : **D-XX – Titre** : décision, puis raison.

---

## Projet et technique

**D-01 – Projet nouveau.** Construction de zéro, aucune migration de code existant.

**D-02 – Stack.** Laravel + Filament + Livewire + PostgreSQL + Docker, tests Pest, PDF mPDF. Raison : stable, gratuit, rapide à développer, support de l'arabe.

**D-03 – Hébergement.** GitHub privé, Railway (région Europe, la plus proche du Maroc) pour l'essai puis la production. Le passage éventuel sur un PC local reste possible grâce à Docker.

**D-04 – Coupure internet.** Pas de mode hors-ligne dans l'application. Recommandation au client : routeur 4G de secours dans chaque site.

**D-05 – Montants.** Stockés en centimes (bigint). Devise unique : MAD.

**D-06 – Quantités.** Quantités entières (unité : pièce, bidon, kit…). Pas de vente au litre ni de stock en fût : les huiles sont achetées et vendues par bidon fermé (1L, 4L, 5L…).

## Sites et stock

**D-07 – Trois sites.** Dépôt Central (DEP), Magasin 01 (M01), Magasin 02 (M02). La table `sites` permet d'en ajouter d'autres plus tard.

**D-08 – Stock par mouvements.** Le registre `stock_movements` est la source de vérité. Une table `stock_levels` (produit × site) sert de cache, mise à jour dans la même transaction. Une commande `stock:verify` compare cache et registre.

**D-09 – Valorisation.** Coût Unitaire Moyen Pondéré (CUMP) par produit, au niveau de l'entreprise. Les transferts entre sites se font au CUMP, sans impact sur la marge. À confirmer par le comptable.

**D-10 – Ordre de sortie.** Pour les pneus neufs, le système propose en priorité le lot au DOT le plus ancien.

**D-11 – Transferts simplifiés.** Trois statuts : En transit, Reçu, Annulé. La sortie du site source se fait à l'envoi, l'entrée au site destinataire à la réception. Les écarts à la réception sont enregistrés avec motif.

**D-12 – Pneus d'occasion.** Suivis individuellement (une ligne par pneu), avec contrôle de sécurité obligatoire avant mise en vente et étiquette QR imprimable.

**D-13 – Réservation.** Un devis accepté ou une vente en brouillon peut réserver du stock. Le stock n'est réellement déduit qu'à la validation de la vente.

## Ventes et documents

**D-14 – Numérotation.** Une série par type de document et par année pour toute l'entreprise (ex. `FA-2026-000123`). Le site figure sur le document. Numéro attribué à la validation, avec verrouillage. À valider par le comptable.

**D-15 – Correction de facture.** Uniquement par avoir. Aucune suppression ni modification d'une facture validée.

**D-16 – Clients professionnels et flottes.** Les livraisons peuvent se faire par bons de livraison / bons de travail, regroupés ensuite en facture récapitulative (mensuelle ou à la demande).

**D-17 – Niveaux de prix.** Trois niveaux : Particulier, Professionnel, Revendeur, plus des prix spécifiques possibles par client.

**D-18 – Protection des marges.** Prix de vente suggéré = coût + marge par catégorie. Vente sous le coût bloquée sans approbation. Remise plafonnée par rôle ; au-delà, approbation du propriétaire.

**D-19 – TVA.** Taux unique de 20 % sur tous les produits et services. Le taux est un paramètre de l'application (non codé en dur) et il est enregistré sur chaque ligne de document, afin qu'un changement futur n'altère jamais les documents déjà émis.

## Crédit et paiements

**D-20 – Compte client.** Chaque client a un compte (débit : factures, crédit : paiements et avoirs). Un paiement peut être réparti sur plusieurs factures (table d'affectation).

**D-21 – Plafond de crédit.** Dépassement bloqué sans approbation du propriétaire.

**D-22 – Moyens de paiement.** Espèces, carte, virement, chèque, LCN / effet de commerce, autres configurables. Chèques et LCN suivis avec échéance et statut. Un chèque rejeté rétablit la dette sans effacer l'historique.

**D-23 – Soldes d'ouverture.** Les dettes existantes (cahier, Excel) sont importées comme solde d'ouverture daté par client, sans ressaisie de l'historique.

## Atelier, compatibilité, divers

**D-24 – Compatibilité véhicules.** Seules les compatibilités **vérifiées** sont présentées comme compatibilités. Sources : données vérifiées fournies par l'entreprise, saisie manuelle par un utilisateur autorisé, import Excel. Chaque ligne a une source, une date, un auteur et un statut. L'historique des véhicules clients (ce qui a déjà été monté) est affiché séparément, comme information, jamais comme compatibilité. Pas de base de données commerciale (type TecDoc) en V1.

**D-25 – Approbations.** Module central de demandes d'approbation (remise, crédit, vente sous le coût, ajustement de stock, annulation). Le propriétaire est notifié et décide depuis son téléphone.

**D-26 – WhatsApp.** V1 : liens `wa.me` avec messages pré-remplis (gratuit). API WhatsApp Business (payante) reportée.

**D-27 – Code-barres.** Reporté, sauf étiquettes QR pour les pneus d'occasion (et éventuellement produits) lisibles par la caméra du téléphone.

**D-28 – Hors périmètre V1.** Paie, planning RH, e-commerce, comptabilité complète (export uniquement), API WhatsApp, base TecDoc.

**D-29 – Verrouillage journalier.** Après clôture de caisse d'un site, les opérations de cette journée ne sont plus modifiables sans permission du propriétaire, avec motif obligatoire.

**D-30 – Paramètres fiscaux.** Droit de timbre, règles de numérotation, mentions légales et traitement fiscal des pneus d'occasion et des reprises sont des **paramètres configurables** (activables / désactivables, taux et seuils modifiables) en attendant la validation du comptable. Aucune de ces règles n'est codée en dur.
