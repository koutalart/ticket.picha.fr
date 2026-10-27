import {keepPreviousData, useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {emailInboxClient} from "../api/email-inbox.client.ts";

export const SEARCH_EMAIL_RECIPIENTS_QUERY_KEY = 'searchEmailRecipients';

export const useSearchEmailRecipients = (organizerId: IdParam, query: string, enabled: boolean) => {
    return useQuery({
        queryKey: [SEARCH_EMAIL_RECIPIENTS_QUERY_KEY, organizerId, query],
        queryFn: async () => {
            const {data} = await emailInboxClient.recipients(organizerId, query);
            return data;
        },
        enabled: !!organizerId && enabled,
        placeholderData: keepPreviousData,
    });
};
