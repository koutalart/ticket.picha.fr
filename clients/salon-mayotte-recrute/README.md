# Salon Mayotte Recrute (client grand compte)

Branche `client/salon-mayotte-recrute`. Personnalisation de l'organisateur et du salon 2026.

| | |
|---|---|
| Compte | n°7 (utilisateur n°11, mourchid@mayotterecrute.fr) |
| Organisateur | n°10 « Mayotte Recrute » (association), site salon.mayotterecrute.fr (leur domaine, pas de domaine perso chez nous) |
| Événement | n°13 « Salon Mayotte Recrute 2026 », 23 et 24 octobre 2026, 8h30 à 16h30, lycée des Lumières, Kawéni + en ligne |
| Couleurs | bleu `#2E3772` vers `#5260A7`, dégradé `#F6C31D` vers `#E5361D`, police Montserrat (proche de leur Metropolis) |
| Inscriptions | deux parcours : Candidats (sur place 500, en ligne) et Exposants, gratuits, questions propres à chaque billet |
| Frais | payés par l'acheteur (règle commune ; entrées gratuites) |

## Contenu

- `scripts-prod/page-salon-2026.php` : organisateur, événement, catégories, billets, questions, images, vérification du compte. `ESSAI=1` annule tout.
- `design/fond-video.py` : boucle de 25 s tirée de la rétrospective 2024 (YouTube _xi-0qR3UHc), recadrée pour retirer les textes incrustés.
- `design/motion-salon.py` : messages animés (ouverture, vendredi, samedi, candidats, exposants).
- `design/couverture.py` : miniature 1280x720.
- `visuels/` : logos extraits de leur site, photo, miniature.

## Demandes au noyau (Picha / Dashboard)

1. Question de type fichier (CV en PDF).
2. Modèle de page « Salon » : en-tête avec un bouton par parcours, lien direct par catégorie de billets.

## Règles

Même base que `staging` (fusionner `staging` ici après chaque fusion). Textes publics sans •, ·, —, –.
