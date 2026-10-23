import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {contactClient} from "../api/contact.client.ts";

export const GET_CONTACT_QUERY_KEY = 'getContact';

export const useGetContact = (organizerId: IdParam, contactId: IdParam) => {
    return useQuery({
        queryKey: [GET_CONTACT_QUERY_KEY, organizerId, contactId],
        queryFn: async () => await contactClient.get(organizerId, contactId),
        enabled: !!contactId,
    });
}
