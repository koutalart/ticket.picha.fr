import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {contactClient} from "../api/contact.client.ts";

export const GET_CONTACT_TAGS_QUERY_KEY = 'getContactTags';

export const useGetContactTags = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_CONTACT_TAGS_QUERY_KEY, organizerId],
        queryFn: async () => await contactClient.tags(organizerId),
    });
}
