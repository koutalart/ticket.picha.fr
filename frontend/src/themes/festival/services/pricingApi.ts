import { TicketDay } from "../types/festivalTheme.types";

/**
 * Couche d'abstraction pour le formatage des prix.
 * Étape 3 : pourra intégrer les vraies règles de devise/taxes Hi.Events.
 */

export const formatTicketPrice = (day: TicketDay): string => {
    const currencyLabel = day.currency === "MGA" ? "Ar" : day.currency;
    return `${day.price.toLocaleString("fr-FR")} ${currencyLabel}`;
};
