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

Pour chaque cas client relié à un événement, comparer avec les chiffres publiés. S'ils ont changé :
- mettre à jour `attendees`, `results`, `headline`, `summary` et `updatedAt` (date du jour) ;
- avant l'événement : ventes ou inscriptions, rythme de vente, offres épuisées ;
- après l'événement : personnes scannées à l'entrée, taux de présence, heure de pointe,
  ventes sur place, puis rédiger `dayOf` à partir des scans par heure.

## Règles de rédaction (SEO)

- Français, ton expert et factuel ; chaque chiffre vient de `event-stats.sh`.
- `summary` sert de meta description : 160 caractères maximum, chiffre clé et lieu.
- `headline` (H1) : mot-clé principal en tête (« Billetterie de concert à Mayotte », « Journée portes ouvertes »…).
- Jamais de chiffre d'affaires ni de montant encaissé sans accord écrit du client.
- Jamais de citation sans `approved: true`, donné par la personne citée.
- Un résultat ne s'affiche que s'il valorise le client : sinon, ne pas l'afficher.
- Ne pas toucher au sitemap des domaines personnalisés (innocent976.yt).
