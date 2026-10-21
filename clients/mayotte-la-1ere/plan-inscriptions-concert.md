# Plan de configuration des inscriptions : Concert des Générations (événement 14)

Cahier des charges : dossier KULTUR'M, section 09. Rien n'est appliqué tant que Jo n'a pas validé. Sauvegarde de la base avant chaque changement.

Légende : ✅ déjà en place · 🔧 la plateforme sait le faire, réglage à appliquer · ❌ manque, demande du code dans le noyau.

## 1. Formulaire

| Besoin | État | Réglage |
|---|---|---|
| Nom et prénom tels que sur la pièce d'identité | ✅ | Fiche par billet (attendee_details_collection_method = PER_TICKET, require_attendee_details). 🔧 Ajouter au-dessus des champs : « Nom et prénom tels qu'ils figurent sur votre pièce d'identité » (texte d'aide du billet ou message avant paiement). |
| Téléphone | ✅ | Question de commande « Numéro de téléphone » (PHONE, obligatoire). |
| E-mail | ✅ | Champ natif de la commande. |
| Commune | ✅ | Question de commande, liste des 17 communes + « Hors de Mayotte ». |
| Profil | ✅ | Question de commande « Vous venez en tant que » (5 choix). 🔧 Ajouter « Chama » si utile. |
| Nombre de places : 2 maximum, chaque accompagnant nommé | ✅ | Billet « Entrée gratuite sur inscription », max_per_order = 2, une fiche nominative par billet. |
| Consentement | ✅ | Case « Pièce d'identité » (obligatoire) et case « Informations » (facultative). 🔧 Ajouter une case de consentement RGPD obligatoire (finalité : gestion de l'accès et sécurité, suppression après le bilan). |
| Mineurs | ❌ (règles à fournir) | 🔧 Possible tout de suite par une question par billet « Cette personne est-elle mineure ? » + texte des conditions. Contrôle d'âge automatique : ❌. |

## 2. Confirmation et billet

| Besoin | État | Réglage |
|---|---|---|
| Message immédiat, billet nominatif avec QR code | ✅ | E-mail de confirmation + billet PDF par participant, nom imprimé, QR code. |
| Rappel « pièce d'identité obligatoire » | ✅ / 🔧 | ✅ dans le message après inscription. 🔧 Ajouter sur le billet (ticket_design_settings.footer_text) et dans le pied de l'e-mail (email_footer_message). |
| Habillage du billet aux couleurs de l'événement | 🔧 | ticket_design_settings : couleur d'accent #b8963e, logo. |

## 3. Rappel J-2 (jeudi 12 novembre)

| Besoin | État | Réglage |
|---|---|---|
| E-mail de rappel programmé | 🔧 | Message aux participants avec envoi programmé (scheduled_at) le 12/11 : horaires (accueil 18h30), accès, pièce d'identité, lien d'annulation. Texte à valider par Jo. |
| SMS de rappel | 🔧 / ❌ | Messagerie SMS organisateur (sms.picha.fr) disponible pour un envoi groupé manuel le 12/11. SMS programmé automatiquement à une date : à confirmer côté noyau, sinon ❌. |

## 4. Annulation par le participant

| Besoin | État | Réglage |
|---|---|---|
| Annuler pour libérer sa place | ❌ | Aujourd'hui seul l'organisateur annule une commande. Le libre-service participant (allow_attendee_self_edit) permet de modifier ses informations, pas d'annuler. Demande noyau : lien « Je ne viens plus » dans l'e-mail et le rappel, qui annule le billet et libère la place (et déclenche la liste d'attente). |

## 5. Jauge, quotas, sur-réservation, liste d'attente

| Besoin | État | Réglage |
|---|---|---|
| Jauge ≈ 300 | ✅ | Billet public à 300 places. |
| Quotas réservés (Chama et remettants, artistes et musiciens, invités Mayotte La 1ère, presse et influenceurs) | 🔧 | Un billet par quota, caché (is_hidden_without_promo_code + code d'invitation par quota, ou visible seulement au guichet), et une capacité partagée (capacity assignment) qui plafonne l'ensemble à la jauge totale. Volumes à fournir. |
| Sur-réservation 5 à 10 % si la sécurité le permet | 🔧 (manuel) | Fixer la capacité à 315 ou 330 au lieu de 300, sur décision de Mayotte La 1ère. Pas de pourcentage automatique. |
| Liste d'attente automatique, réattribution dans l'ordre | 🔧 | waitlist_enabled sur le billet (déjà activé) + waitlist_auto_process = oui et délai de réponse (waitlist_offer_timeout_minutes, ex. 24 h). Ne marche pleinement qu'avec l'annulation participant (point 4). |

## 6. Ouverture, clôture

| Besoin | État | Réglage |
|---|---|---|
| Ouverture lundi 26/10, clôture vendredi 13/11 à midi | ✅ | Dates de vente du billet (heure de Mayotte). |
| Clôture dès que la jauge est atteinte | ✅ | Billet complet → liste d'attente. |

## 7. Accueil le 14 novembre

| Besoin | État | Réglage |
|---|---|---|
| Scan du QR code | ✅ | Liste de contrôle des entrées (check-in list) + scan au téléphone ou au kiosque. 🔧 Créer une liste « Entrée du public » ouverte le 14/11 de 16h30 à 22h30. |
| Vérification de la pièce d'identité (nom identique) | ✅ / 🔧 | Le nom du participant s'affiche au scan ; vérification visuelle par l'agent. Aucune copie de pièce conservée. |
| Liste nominative exportable pour la sécurité | ✅ | Export des participants (CSV/Excel) avec réponses aux questions. |
| Tableau de bord (inscrits, restants, quotas, liste d'attente, profil, commune, présence) | 🔧 / ❌ | Chiffres de base et exports : ✅. Répartition par profil et par commune en graphique dans le tableau de bord : ❌ (demande noyau), faisable en attendant par export. |

## Ce qui demande du code (noyau)

1. Annulation par le participant (lien « Je ne viens plus ») qui libère la place et relance la liste d'attente.
2. SMS de rappel programmé à une date (si la messagerie SMS ne le permet pas encore).
3. Statistiques des réponses aux questions (profil, commune) dans le tableau de bord.
4. Contrôle d'âge des mineurs, si Mayotte La 1ère fixe une règle stricte.
