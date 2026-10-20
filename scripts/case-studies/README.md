# Mise à jour quotidienne des cas clients

Les cas clients (`frontend/src/components/routes/case-studies/caseStudies.ts`) qui ont un
`ticketingEventId` sont actualisés une fois par jour à partir des données de la billetterie.

## Automatisation

Le workflow GitHub Actions `.github/workflows/case-studies-daily.yml` tourne chaque jour à 7 h (heure de Mayotte) :

1. il lit les `ticketingEventId` de `caseStudies.ts` ;
2. il récupère les chiffres agrégés sur `GET https://ticket.picha.fr/api/public/case-study-stats?event_ids=4,6`,
   protégé par un jeton (`Authorization: Bearer <CASE_STUDY_STATS_TOKEN>`, sinon réponse 404) ;
3. Claude (claude-code-action) réécrit les cas clients selon les règles ci-dessous ;
4. le workflow vérifie que seul `caseStudies.ts` a changé, que les descriptions font 160 caractères au plus et que TypeScript compile ;
5. il ouvre ou met à jour la PR `auto/cas-clients` (« Cas clients : chiffres du JJ/MM »). Elle n'est jamais fusionnée automatiquement.

Secrets GitHub requis : `CASE_STUDY_STATS_TOKEN` (même valeur que dans `backend/.env.production`) et `ANTHROPIC_API_KEY`.
Lancement manuel : Actions → « Cas clients - mise à jour quotidienne » → Run workflow.

Données renvoyées, par événement : dates locales, lieu, organisateur, billets actifs par canal (`STRIPE` = en ligne,
`HORS_LIGNE` = préventes ou ventes manuelles, `GRATUIT` = inscriptions gratuites) et par produit, billets par jour,
jauges, personnes scannées et scans par heure. Aucun montant encaissé, aucune donnée personnelle.

`scripts/case-studies/event-stats.sh` fait la même lecture directement en base (SSH, lecture seule), pour un contrôle manuel.

## Mise à jour d'un cas client

Un cas client marqué `lockedFigures: true` porte des chiffres fournis par l'organisateur (ex. visiteurs comptés sur place,
inscriptions manuelles) : ne jamais le modifier, même si la billetterie donne d'autres chiffres.

Pour chaque cas client relié à un événement, comparer avec les chiffres publiés. S'ils ont changé :
- mettre à jour `attendees`, `results`, `headline`, `summary` et `updatedAt` (date du jour) ;
- avant l'événement : ventes ou inscriptions, rythme de vente, offres épuisées ;
- après l'événement : personnes scannées à l'entrée, taux de présence, heure de pointe,
  ventes sur place, puis rédiger `dayOf` à partir des scans par heure.

## Nouveaux événements

Chaque matin, le workflow demande aussi les événements en ligne terminés depuis 7 jours (`?ended_since=AAAA-MM-JJ`)
qui n'ont pas encore de cas client (champ `candidates`).

- Seuil : au moins 100 billets payés ou inscrits (hors invitations `GRATUIT` pour une billetterie payante),
  ou au moins 20 % de la jauge totale. En dessous, ne rien créer.
- Au-dessus du seuil : ajouter un cas client **en brouillon** (`draft: true`) : il n'est ni affiché, ni listé, ni dans le sitemap
  tant que l'équipe ne l'a pas validé (accord du client) et passé à `draft: false`.
- `slug` : organisateur puis événement, en minuscules sans accents (ex. `innocent-event-halloween`) ; `client` = organisateur,
  `eventName` = nom de l'événement ; `sector` d'après la catégorie ; `date`, `location`, `eventStartDate`, `eventEndDate`
  (heure locale avec décalage, ex. `+03:00` à Mayotte, `+01:00` à Paris en hiver), `eventLocality`, `eventCountryCode` ;
  `services` d'après les canaux réellement utilisés (billetterie en ligne, préventes, vente sur place, inscriptions, contrôle d'accès si scans) ;
  `results` (2 à 4 chiffres), `dayOf` à partir des scans par heure, `headline` et `summary` selon les règles ci-dessous.
- Ne rien inventer sur le contexte : `challenge` décrit seulement l'événement (date, lieu, type, canaux de vente) d'après les données.

## Règles de rédaction (SEO)

- Français, ton expert et factuel ; chaque chiffre vient de `event-stats.sh`.
- `summary` sert de meta description : 160 caractères maximum, chiffre clé et lieu.
- `headline` (H1) : mot-clé principal en tête (« Billetterie de concert à Mayotte », « Journée portes ouvertes »…).
- Jamais de chiffre d'affaires ni de montant encaissé sans accord écrit du client.
- Jamais de citation sans `approved: true`, donné par la personne citée.
- Un résultat ne s'affiche que s'il valorise le client : sinon, ne pas l'afficher.
- Toujours compter les billets payés ou inscrits (`tickets_by_channel_product`, hors `GRATUIT` pour une billetterie payante),
  jamais une jauge ni un `quantity_sold` de `prices`, qui inclut des places réservées mais non payées.
  Une offre est « épuisée » quand `quantity_sold` atteint `capacity`, mais le nombre affiché reste celui des billets payés.
- Avant l'événement, ne pas publier un nombre d'inscrits ou de billets inférieur à 20 % de la jauge totale :
  laisser le cas client tel quel, sans résultat chiffré.
- Garder les mots-clés déjà présents dans `headline` et `summary` (anniversaire, lieu, type d'événement) quand on les met à jour.
- Ne pas toucher au sitemap des domaines personnalisés (innocent976.yt).
