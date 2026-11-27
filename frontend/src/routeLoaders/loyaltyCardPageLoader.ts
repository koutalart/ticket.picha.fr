import {LoaderFunctionArgs} from "react-router";
import {getCustomDomainOrganizer} from "../utilites/customDomain.ts";

/**
 * "/fidelite" on an organizer's own domain: the organizer comes from the domain.
 */
export const customDomainLoyaltyCardLoader = async ({request}: LoaderFunctionArgs) => {
    const organizer = getCustomDomainOrganizer(request);
    if (!organizer) {
        throw new Response('Not Found', {status: 404});
    }

    return {organizerId: String(organizer.id)};
};
