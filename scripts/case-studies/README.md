# Mise à jour quotidienne des cas clients

Les cas clients (`frontend/src/components/routes/case-studies/caseStudies.ts`) qui ont un
`ticketingEventId` sont actualisés une fois par jour à partir des données de la billetterie.

## Données

```bash
scripts/case-studies/event-stats.sh <event_id> [...]
```

Lecture seule sur la base de production, via SSH. Renvoie, par événement :
dates locales, lieu, organisateur, billets actifs par canal (`STRIPE` = en ligne,
`HORS_LIGNE` = préventes ou ventes manuelles, `GRATUIT` = inscriptions gratuites)
et par produit, billets par jour, jauges, nombre de personnes scannées et scans par heure.
Aucun montant, aucune donnée personnelle.

## Procédure

1. Travailler dans un worktree dédié, sur la branche `auto/cas-clients` repartie de `origin/staging`.
2. Lancer `event-stats.sh` avec tous les `ticketingEventId` des cas clients.
3. Pour chaque cas client, comparer avec les chiffres publiés. S'ils ont changé :
   - mettre à jour `attendees`, `results`, `headline`, `summary` et `updatedAt` (date du jour) ;
   - avant l'événement : ventes ou inscriptions, rythme de vente, offres épuisées ;
   - après l'événement : personnes scannées à l'entrée, taux de présence, heure de pointe,
     ventes sur place, puis rédiger `dayOf` à partir des scans par heure ;
4. `npx tsc --noEmit` ne doit signaler aucune erreur dans `case-studies/` ni `marketing/`.
5. Commit, push, et une seule PR ouverte à la fois (« Cas clients : chiffres du JJ/MM »).
   Ne jamais fusionner : la mise en production reste manuelle.

## Règles de rédaction (SEO)

- Français, ton expert et factuel ; chaque chiffre vient de `event-stats.sh`.
- `summary` sert de meta description : 160 caractères maximum, chiffre clé et lieu.
- `headline` (H1) : mot-clé principal en tête (« Billetterie de concert à Mayotte », « Journée portes ouvertes »…).
- Jamais de chiffre d'affaires ni de montant encaissé sans accord écrit du client.
- Jamais de citation sans `approved: true`, donné par la personne citée.
- Un résultat ne s'affiche que s'il valorise le client : sinon, ne pas l'afficher.
- Ne pas toucher au sitemap des domaines personnalisés (innocent976.yt).
