import {keepPreviousData, useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {newsletterSubscriberClient, NewsletterSubscribersParams} from "../api/organizer.client.ts";

export const GET_NEWSLETTER_SUBSCRIBERS_QUERY_KEY = 'getNewsletterSubscribers';

export const useGetNewsletterSubscribers = (organizerId: IdParam, params: NewsletterSubscribersParams) => {
    return useQuery({
        queryKey: [GET_NEWSLETTER_SUBSCRIBERS_QUERY_KEY, organizerId, params],
        queryFn: () => newsletterSubscriberClient.all(organizerId, params),
        enabled: !!organizerId,
        placeholderData: keepPreviousData,
    });
};
