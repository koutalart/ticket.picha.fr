# Innocent Event : innocent976.yt (client grand compte)

Branche `client/innocent-event`. Tout ce qui est propre à ce client : habillage, visuels, vidéos, scripts de mise en production, pilote automatique.

## Le client

| | |
|---|---|
| Compte | n°3 « Anael Hassan » (utilisateur n°6, Anael ; aussi Adjison Ben omary et le guichet hello@digit.yt) |
| Organisateur | n°4 « Innocent Event », domaine personnalisé **innocent976.yt** |
| Événements | 4 « Triangle des bermudes » (11/10, encaissements physiques en attente), 7 « Halloween » (31/10, Le 5/5, Mamoudzou) |
| Couleurs | orange du flyer `#F7941D`, rouge `#DC1E1E` |
| Frais de plateforme | payés par l'acheteur (règle commune, sans exception) |

## Règles du client

1. innocent976.yt doit rediriger vers l'événement en cours, basculer sur chaque nouvel événement, et revenir à la page principale entre deux événements (demandé au noyau, code commun).
2. Vente flash d'abord : l'entrée normale reste masquée en ligne et au guichet tant que la vente flash n'est pas épuisée.
3. Pas de code promo sur la vente flash : « tinemada » ne vaut que sur l'entrée simple (produit 10).
4. Vidéo de couverture qui pousse à acheter, avec des arguments vrais uniquement.
5. Ne pas toucher aux textes d'Anael sans validation ; relire tous les textes copiés d'un événement précédent.

## Contenu

- `scripts-prod/` : scripts tinker passés en production pour Halloween (habillage, ordre des billets, vidéo/affiche/textes, entrée simple masquée, billet orange, logo de l'organisateur). Chacun vérifie les identifiants et titres avant d'écrire, et peut être relancé.
- `design/motion-halloween.py` : vidéo de couverture en motion design, 25 s en boucle. Arguments : polices, logo, fond, sortie, jour (`J-5`, `J-4`, `J-3`, `J-2`, `DEMAIN`, `CE-SOIR` ou vide) et tarif (`15` ou `20`).
- `design/fond.filtre` : graphe ffmpeg du fond (flyer en zoom lent, passages des clips de Le D et Jah D One étalonnés en rouge, boucle sans coupure).
- `pilote/` : pilote automatique de la page Halloween (voir plus bas).
- `visuels/` : flyer, couverture, image de partage, logo de l'organisateur.

Les vidéos (sources YouTube et 14 rendus) ne sont pas versionnées : elles sont dans `apercu-assistant/innocent-event/video/` sur le poste de travail et dans le dossier du pilote sur le serveur.

## Pilote automatique Halloween

Sur le serveur, dossier `/home/ubuntu/picha-clients/innocent-event/halloween/` (hors du code de la plateforme), lancé par cron toutes les 5 minutes (utilisateur `ubuntu`) :

- calcule la clé attendue : jour en heure de Mayotte (`base` jusqu'au 25/10, puis `J-5` … `CE-SOIR`) et tarif (`15`, ou `20` dès que les 100 places flash sont vendues) ;
- si la clé change : sauvegarde de la base (sinon rien n'est modifié), puis mise en ligne de la vidéo `videos/halloween-<clé>.mp4`, de la première phrase du jour (description et message avant paiement) et, à 20 €, réouverture de l'entrée simple en ligne et au guichet ;
- journal : `pilote.log` ; état : `etat.txt` ;
- après la soirée (01/11 06:00, heure de Mayotte), il retire lui-même sa ligne cron.

Les scripts tournent dans `picha-ticket-prod-queue-worker-1` (même stockage que le site) ; ne jamais laisser de session tinker ouverte dans `backend-1`.

## Règles de la branche

1. **Même base que la plateforme** : cette branche part de `staging` et doit le suivre. Après chaque fusion dans `staging`, on fusionne `staging` dans `client/innocent-event`.
2. **Alerte obligatoire** : toute modification de la plateforme qui touche la personnalisation d'un organisateur doit être signalée avant d'aller en production.
3. Rien de ce dossier n'est utilisé par l'application : une fusion de cette branche ne change pas le comportement de la plateforme.
