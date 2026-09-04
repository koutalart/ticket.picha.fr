/**
 * Types génériques du thème "Festival" — moteur de landing page réutilisable.
 * Ces types ne connaissent aucun événement particulier : un nouvel événement
 * (Sanaa, concert, conférence...) fournit simplement un objet conforme à
 * FestivalEventData, sans qu'aucun composant n'ait à changer.
 */

export interface Artist {
    id: string;
    name: string;
    imageUrl?: string;
}

export interface TicketDay {
    id: string;
    dayLabel: string;        // ex: "Mer. 5 Août"
    date: string;            // ISO date, ex: "2026-08-05"
    title: string;           // ex: "Grand Carnaval — Bal d'ouverture"
    headliners: Artist[];
    lineup: Artist[];
    price: number;
    currency: string;        // ex: "MGA"
    colorAccent: string;     // couleur de la bulle nuage pour ce jour
    ticketId?: string;       // futur : id du produit Hi.Events réel (Étape 3)
    isSoldOut?: boolean;
}

export interface Sponsor {
    id: string;
    name: string;
    logoUrl?: string;
}

export interface FaqItem {
    id: string;
    question: string;
    answer: string;
}

export interface VenueInfo {
    name: string;
    address: string;
    openingHours: string;
    accessInfo: string;
}

export interface FestivalEventData {
    slug: string;                    // ex: "somaroho-2026" — utilisé dans l'URL /festival/:slug
    eventName: string;                // ex: "Somaroho Festival"
    edition: string;                  // ex: "13e édition"
    tagline: string;                  // ex: "Le plus grand festival de Nosy Be"
    startDate: string;                // ISO date
    endDate: string;                  // ISO date
    heroImageUrl: string;
    venue: VenueInfo;
    days: TicketDay[];
    sponsors: Sponsor[];
    faq: FaqItem[];
    stats: { label: string; value: string }[];
    /**
     * Lien vers l'event réel Hi.Events (rempli à l'Étape 3).
     * Reste optionnel tant que le thème tourne sur données mockées.
     */
    hiEventsEventId?: number;
}
