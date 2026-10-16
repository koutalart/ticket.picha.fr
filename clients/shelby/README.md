# Shelby (client grand compte)

Branche `client/shelby`. Tout ce qui est propre à ce client : habillage, visuels, vidéos, scripts de mise en production.

## Le client

| | |
|---|---|
| Compte | à créer (inscription par le client lui-même, puis vérification manuelle) |
| Organisateur | nom définitif à confirmer |
| E-mail de connexion | à confirmer |
| Domaine personnalisé | à confirmer |
| Événements | aucun pour l'instant |
| Frais de plateforme | payés par l'acheteur (0,99 €/billet), règle commune sans exception |

## Contenu

- `scripts-prod/` : scripts tinker passés en production pour ce compte. Chacun vérifie les identifiants avant d'écrire et peut être relancé. Sauvegarde avant toute écriture en base.
- `design/` : outils de design propres au client (miniatures, vidéos de couverture).
- `visuels/` : visuels fournis par le client et versions en ligne.

Les vidéos lourdes ne sont pas versionnées.

## Règles de la branche

1. **Même base que la plateforme** : cette branche part de `staging` et doit le suivre. Après chaque fusion dans `staging`, on fusionne `staging` dans `client/shelby`.
2. **Alerte obligatoire** : toute modification de la plateforme qui touche la personnalisation d'un organisateur doit être signalée avant d'aller en production. Cela concerne la page événement, la vidéo de couverture, les thèmes et couleurs, les paramètres d'événement ou d'organisateur, le domaine personnalisé, le site organisateur, les e-mails et les billets.
3. Rien de ce dossier n'est utilisé par l'application : une fusion de cette branche ne change pas le comportement de la plateforme.
