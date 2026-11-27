# CCI Mayotte

Branche `client/cci-mayotte`. Tout ce qui est propre à ce client : visuels, textes, scripts de mise en production.

## Le client

| | |
|---|---|
| Nom | CCI Mayotte (Maison de l'Entreprise) |
| Contact | Catherine Mkadara, Événementiel / Attractivité du Territoire (coordonnées dans la mémoire `cci-mayotte-foire-industrie`) |
| Compte / organisateur | compte 18 / organisateur 22 « CCI Mayotte », créés le 18/11/2026 pour la Foire de l'Industrie |
| Utilisateur | 22 (Catherine) ; Jo (1) ADMIN et Brayan (23) SUPERADMIN dans le compte 18 |
| Ligne SMS | ligne PICHA partagée (line settings 1) |
| Équipe SMS | contact 27 Catherine (ORGANIZER). Brayan refusé comme COPY : son numéro est dans l'équipe interne PICHA |
| Facturation | pas d'acompte, facture par Chorus Pro sur bon de commande. Nuit de l'Orientation : test offert (contrôle d'accès, facture à zéro) |

## Événements

### Foire de l'Industrie, 21/11/2026

Événement 28, fait depuis la conversation indépendante 43355fdd (scripts dans `apercu-assistant/cci-mayotte/`). Voir la mémoire `cci-mayotte-foire-industrie`.

### La Nuit de l'Orientation, 5/12/2026

- Samedi 5 décembre 2026, 8h00 à 12h30, Technopôle de Dembéni. Démonstrations métiers, conférences, stands, ateliers, animations, job dating.
- Partenaires : Le mois de la découverte des métiers, Centre d'Étude de Langues, ELO, Negoventis, ANAF, Département de Mayotte, CADEMA, CCI Mayotte.
- Affiche : `visuels/nuit-orientation-2026-affiche.jpg` ; couverture 1950×650 et image de partage 1200×630 tirées de l'affiche.
- Événement 32 créé le 27/11/2026 en brouillon (`scripts/creer-nuit-orientation-2026.php`) : tarif 62 « Entrée gratuite », sans jauge, 1 à 40 places par inscription (classes), infos par commande. Questions 60 téléphone (obligatoire), 61 Vous êtes, 62 Établissement ou école, 63 Commune, 64 Ce qui vous intéresse.
- Page (`scripts/page-nuit-orientation-2026.php`) : base de la Marche des Amis, en-tête en carte, inscription intégrée, thème clair, accent rouge #C8102E, fond miroir de la couverture.
- SMS (`scripts/sms-nuit-orientation-2026.php`, `scripts/rappels-nuit-orientation-2026.php`) : confirmation signée Anicka ; rappels 39 J-7 (28/11 18h00), 40 la veille (4/12 18h00), 42 jour J (5/12 6h30) ; rappel par défaut 41 annulé. Assistant SMS vouvoiement, envoi automatique, fenêtre d'assistance active. Ces consignes ont remplacé celles de la Foire (organisateur 22).
- Faits du chat 48 à 56 (`live-chat:fact 32 --list`), les mêmes que dans les consignes SMS.
- Compte rendu quotidien à 20h45 avec l'activité du jour (`events:daily-report 32`).

Toutes les heures sont en heure de Mayotte (UTC+3).

## Règles

Mêmes règles que les autres conversations clients : pas de modification du code commun (demandes au noyau « Picha / Dashboard »), sauvegarde avant toute écriture en base, textes publics sans caractères « IA » (•, ·, —, –), aucun message à la CCI sans « envoie » de Jo. Un e-mail vers @mayotte.cci.fr peut être filtré : doubler par SMS.
