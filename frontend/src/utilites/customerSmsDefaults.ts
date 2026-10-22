import {CustomerAddressForm} from "../locales.ts";

/**
 * Default texts of the SMS sent to attendees. They are always in French, like the other customer messages,
 * and match the backend defaults (lang/fr.json and lang/fr_TU.json).
 */
const SMS_CONFIRMATION = {
    vous: "C'est validé, votre place pour {evenement} est réservée. Rendez-vous le {date}, accueil dès {heure}. Gérer ma venue : {lien}",
    tu: "C'est validé, ta place pour {evenement} est réservée. Rendez-vous le {date}, accueil dès {heure}. Gérer ma venue : {lien}",
};

const SMS_CANCELLATION = {
    vous: "C'est noté, votre inscription à {evenement} est annulée et votre place est libérée. Au plaisir de vous revoir.",
    tu: "C'est noté, ton inscription à {evenement} est annulée et ta place est libérée. Au plaisir de te revoir.",
};

export const SMS_REMINDER_TU = "Salut {prenom}, petit rappel : {evenement}, c'est le {date} à {heure}. On t'attend, à très vite !";

export const defaultSmsConfirmationMessage = (addressForm: CustomerAddressForm): string => SMS_CONFIRMATION[addressForm];

export const defaultSmsCancellationMessage = (addressForm: CustomerAddressForm): string => SMS_CANCELLATION[addressForm];
