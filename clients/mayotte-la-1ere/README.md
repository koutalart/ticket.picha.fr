# Mayotte la 1ère (client grand compte)

Branche `client/mayotte-la-1ere`. Tout ce qui est propre à ce client : habillage, visuels, textes, scripts de mise en production.

## Le client

| | |
|---|---|
| Compte | n°5 « Christine Mkadara » (Christine Mkadara, Aminat Ouildamedine : christine.mkadara@ / aminat.ouildamedine@francetv.fr) |
| Organisateur | n°8 « Mayotte la 1ere » : statut brouillon, fuseau Indian/Mayotte, EUR, pas de domaine personnalisé |
| Thème de la page organisateur | « Midnight » (texte blanc, fond gris, contenu bleu nuit) |
| Événements | n°6 « Journée Portes Ouvertes - Le Salouva vous va si bien », 18/10/2026 07:00–13:00 (heure de Mayotte) |
| Frais de plateforme | payés par l'acheteur (0,99 €/billet), comme pour tous les organisateurs |

À ne pas confondre : l'organisateur n°7 « Mayotte la 1ère » du compte n°1 (PICHA AI) est vide et hors périmètre.

## Contenu

- `scripts-prod/` : scripts tinker passés en production pour ce client. Chacun vérifie les identifiants et titres avant d'écrire et peut être relancé.
- `visuels/` : visuels fournis par le client et visuels en ligne.

## Règles de la branche

1. **Même base que la plateforme** : cette branche part de `staging` et doit le suivre. Après chaque fusion dans `staging`, on fusionne `staging` dans `client/mayotte-la-1ere`.
2. **Code commun interdit ici** : aucune modification de `backend/`, `frontend/`, `docker/` ni des migrations, aucun déploiement. Les évolutions passent par la conversation « Picha / Dashboard ».
3. **Production** : uniquement les données du compte n°5 / organisateur n°8, toujours après une sauvegarde.
4. **Alerte obligatoire** : toute modification de la plateforme qui touche la personnalisation d'un organisateur (page événement, vidéo de couverture, thèmes et couleurs, paramètres d'événement ou d'organisateur, domaine, site organisateur, e-mails, billets) doit être signalée avant d'aller en production.
5. Rien de ce dossier n'est utilisé par l'application : une fusion de cette branche ne change pas le comportement de la plateforme.
