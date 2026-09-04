import { TicketDay } from "../types/festivalTheme.types";

/**
 * Couche d'abstraction pour les actions liées à un billet.
 *
 * Étape actuelle : redirige simplement vers le tunnel d'achat Hi.Events
 * existant, en utilisant hiEventsEventId. Aucune logique de paiement
 * n'est dupliquée ici — Stripe et le checkout restent gérés à 100%
 * par Hi.Events.
 */

export const redirectToHiEventsCheckout = (hiEventsEventId: number | undefined, day: TicketDay) => {
    if (!hiEventsEventId) {
        console.warn("Aucun hiEventsEventId configuré pour cet événement — impossible de rediriger vers le checkout.");
        return;
    }
    // Étape 3 : ce lien pointera vers la vraie page event Hi.Events,
    // idéalement avec le bon produit pré-sélectionné (day.ticketId).
    window.location.href = `/event/${hiEventsEventId}`;
};
