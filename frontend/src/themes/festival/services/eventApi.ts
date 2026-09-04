import { FestivalEventData } from "../types/festivalTheme.types";
import { somarohoEvent } from "../events/somaroho";

/**
 * Couche d'abstraction pour la récupération des données d'un événement festival.
 *
 * Étape actuelle : retourne des données mockées, définies localement dans
 * themes/festival/events/*.ts.
 *
 * Étape 3 (future) : cette fonction appellera l'API publique Hi.Events
 * (GET /public/events/{event.hiEventsEventId}) et transformera la réponse
 * au format FestivalEventData, sans que EventLanding ni aucun composant
 * n'ait à changer.
 */

const mockEventsBySlug: Record<string, FestivalEventData> = {
    [somarohoEvent.slug]: somarohoEvent,
};

/**
 * Version synchrone - utilisée côté client ET serveur (SSR), pour éviter
 * tout mismatch d'hydratation qui surviendrait avec un chargement
 * asynchrone (useEffect ne s'exécute jamais côté serveur). Tant que les
 * données sont mockées localement, aucune vraie latence rʱseau n'est perdue.
 * Étape 3 : si le branchement Hi.Events réintroduit un vrai appel réseau,
 * utiliser un vrai React Router loader (comme publicEventRouteLoader)
 * plutôt que cette fonction, pour rester compatible SSR.
 */
export const getFestivalEventBySlugSync = (slug: string): FestivalEventData | null => {
    return mockEventsBySlug[slug] ?? null;
};

export const getFestivalEventBySlug = async (slug: string): Promise<FestivalEventData | null> => {
    return getFestivalEventBySlugSync(slug);
};
