import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {contactClient} from "../api/contact.client.ts";

export const GET_CONTACT_TIMELINE_QUERY_KEY = 'getContactTimeline';

export const useGetContactTimeline = (organizerId: IdParam, contactId: IdParam) => {
    return useQuery({
        queryKey: [GET_CONTACT_TIMELINE_QUERY_KEY, organizerId, contactId],
        queryFn: async () => await contactClient.timeline(organizerId, contactId),
        enabled: !!contactId,
    });
}
