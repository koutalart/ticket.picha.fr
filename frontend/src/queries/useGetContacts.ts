import {keepPreviousData, useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {contactClient, ContactFilters} from "../api/contact.client.ts";

export const GET_CONTACTS_QUERY_KEY = 'getContacts';

export const useGetContacts = (organizerId: IdParam, filters: ContactFilters) => {
    return useQuery({
        queryKey: [GET_CONTACTS_QUERY_KEY, organizerId, filters],
        queryFn: async () => await contactClient.all(organizerId, filters),
        placeholderData: keepPreviousData,
    });
}
