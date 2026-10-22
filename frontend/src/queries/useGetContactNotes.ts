import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {contactClient} from "../api/contact.client.ts";

export const GET_CONTACT_NOTES_QUERY_KEY = 'getContactNotes';

export const useGetContactNotes = (organizerId: IdParam, contactId: IdParam) => {
    return useQuery({
        queryKey: [GET_CONTACT_NOTES_QUERY_KEY, organizerId, contactId],
        queryFn: async () => await contactClient.notes(organizerId, contactId),
        enabled: !!contactId,
    });
}
