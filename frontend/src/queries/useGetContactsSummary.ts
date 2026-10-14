import {keepPreviousData, useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {contactClient, ContactFilters} from "../api/contact.client.ts";
import {GET_CONTACTS_QUERY_KEY} from "./useGetContacts.ts";

export const useGetContactsSummary = (organizerId: IdParam, filters: ContactFilters) => {
    return useQuery({
        queryKey: [GET_CONTACTS_QUERY_KEY, 'summary', organizerId, filters],
        queryFn: async () => await contactClient.summary(organizerId, filters),
        placeholderData: keepPreviousData,
    });
}
