# Konka Wawa : wawasalegy.com (client grand compte)

Branche `client/konka-wawa`. Tout ce qui est propre à ce client : habillage, visuels, vidéos, scripts de mise en production.

## Le client

| | |
|---|---|
| Compte | n°6 « Konka Wawa » (utilisateur n°10, wawakonkaevent@gmail.com) |
| Organisateur | n°9 « Konka Wawa », domaine personnalisé **wawasalegy.com** |
| Événements | tournée « Wawa – 25 ans de scène » : 8 Marseille, 9 Colmar, 10 Toulouse, 12 Lyon, 11 Limoges |
| Couleurs par ville | Marseille `#F0F43C` · Colmar `#FFB02E` · Toulouse `#50F08C` · Lyon `#5AC8FF` |
| Frais de plateforme | payés par l'acheteur (0,99 €/billet) |

## Contenu

- `scripts-prod/` : scripts tinker passés en production (création de la tournée, descriptions, domaine, miniatures, vidéos, frais). Chacun vérifie les identifiants et titres avant d'écrire, et peut être relancé.
- `design/motion.py` : vidéo de couverture en motion design, 25 s en boucle (ouverture WAWA / VILLE / DATE / SALLE, ville géante en filigrane, bouton « Achète ta prévente en ligne » qui devient wawasalegy.com).
- `design/neon2.py` : miniatures (image principale et image de partage).
- `design/ressources/` : polices (Unbounded, Anton) et fonds de ville.
- `visuels/` : affiches fournies par le client et miniatures en ligne.

Les vidéos sources et rendues (plusieurs centaines de Mo) ne sont pas versionnées : elles sont dans `apercu-assistant/konka-wawa/video/` sur le poste de travail, et les versions publiées sont en production.

## Régénérer une vidéo de couverture

```bash
python design/motion.py design/ressources marseille fond-marseille.mp4 wawa-marseille-couverture.mp4
```

Le fond (ville 8 s puis concert, boucle sans coupure) est préparé avec ffmpeg à partir des sources ; les passages de concert retenus par ville sont notés dans l'historique du projet. Encodage : H.264, 24 images/s, CRF 29, environ 5 Mo.

## Règles de la branche

1. **Même base que la plateforme** : cette branche part de `staging` et doit le suivre. Après chaque fusion dans `staging`, on fusionne `staging` dans `client/konka-wawa`.
2. **Alerte obligatoire** : toute modification de la plateforme qui touche la personnalisation d'un organisateur doit être signalée avant d'aller en production. Cela concerne la page événement, la vidéo de couverture, les thèmes et couleurs, les paramètres d'événement ou d'organisateur, le domaine personnalisé, le site organisateur, les e-mails et les billets.
3. Rien de ce dossier n'est utilisé par l'application : une fusion de cette branche ne change pas le comportement de la plateforme.
