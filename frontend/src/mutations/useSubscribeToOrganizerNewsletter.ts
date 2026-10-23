import {useMutation} from "@tanstack/react-query";
import {organizerPublicClient} from "../api/organizer.client.ts";
import {IdParam} from "../types.ts";

export const useSubscribeToOrganizerNewsletter = () => {
    return useMutation({
        mutationFn: ({eventId, email, consentText}: {
            eventId: IdParam;
            email: string;
            consentText: string;
        }) => organizerPublicClient.subscribeToNewsletter(eventId, {
            email,
            consent: true,
            consent_text: consentText,
        }),
    });
};
