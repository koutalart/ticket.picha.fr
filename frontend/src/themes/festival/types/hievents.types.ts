/**
 * Types transactionnels — données Hi.Events
 * ============================================================
 * Données qui proviennent (Étape 3) de l'API Hi.Events : prix,
 * disponibilité, checkout. Aucune donnée éditoriale ici — voir
 * editorial.types.ts.
 */

import type { IdParam } from '../../../types';

export type HiEventsId = Exclude<IdParam, undefined>;
export type HiEventsEventId = HiEventsId;
export type HiEventsProductId = HiEventsId;

export type TicketAvailabilityStatus =
    | 'available'
    | 'sold_out'
    | 'not_yet_on_sale'
    | 'sale_ended'
    | 'unavailable';

export interface Ticket {
    hiEventsProductId: HiEventsProductId;
    /** Référence vers FestivalDay.id (editorial.types.ts) — jamais l'inverse. */
    festivalDayId: string;
    title: string;
    /**
     * Format à vérifier contre la réponse réelle de l'API Hi.Events
     * (products.price) : valeur décimale ou unité minimale (centimes) ?
     */
    priceAmount: number;
    currency: string;
    availabilityStatus: TicketAvailabilityStatus;
    quantityAvailable: number | null;
    saleStartDate: string | null;
    saleEndDate: string | null;
}

export interface HiEventsCheckoutRef {
    hiEventsEventId: HiEventsEventId;
    hiEventsProductId?: HiEventsProductId;
}
