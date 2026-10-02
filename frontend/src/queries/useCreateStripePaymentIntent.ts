import {useQuery} from "@tanstack/react-query";
import {orderClientPublic} from "../api/order.client.ts";
import {IdParam} from "../types.ts";

export const GET_INITIATE_STRIPE_SESSION_PUBLIC_QUERY_KEY = 'getStripSessionPublic';

export const getStripePaymentIntentQuery = (eventId: IdParam, orderShortId: IdParam) => ({
    queryKey: [GET_INITIATE_STRIPE_SESSION_PUBLIC_QUERY_KEY, eventId, orderShortId],
    queryFn: async () => {
        const {client_secret, account_id, public_key, stripe_platform} = await orderClientPublic.createStripePaymentIntent(
            Number(eventId),
            String(orderShortId),
        );
        return {client_secret, account_id, public_key, stripe_platform};
    },
    retry: false,
    staleTime: 30_000,
    gcTime: 60_000,
});

export const useCreateStripePaymentIntent = (eventId: IdParam, orderShortId: IdParam) => {
    return useQuery(getStripePaymentIntentQuery(eventId, orderShortId));
}
