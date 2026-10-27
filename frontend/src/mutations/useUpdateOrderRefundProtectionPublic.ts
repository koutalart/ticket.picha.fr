import {useMutation, useQueryClient} from "@tanstack/react-query";
import {orderClientPublic} from "../api/order.client.ts";
import {IdParam} from "../types.ts";
import {GET_ORDER_PUBLIC_QUERY_KEY} from "../queries/useGetOrderPublic.ts";
import {GET_ORDER_REFUND_PROTECTION_PUBLIC_QUERY_KEY} from "../queries/useGetOrderRefundProtectionPublic.ts";
import {GET_INITIATE_STRIPE_SESSION_PUBLIC_QUERY_KEY} from "../queries/useCreateStripePaymentIntent.ts";

/**
 * The order total changes, so the order, the offer and the Stripe payment intent are all fetched again:
 * the server replaces a payment intent whose amount no longer matches the order.
 */
export const useUpdateOrderRefundProtectionPublic = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, orderShortId, enabled}: {
            eventId: IdParam,
            orderShortId: IdParam,
            enabled: boolean,
        }) => orderClientPublic.updateRefundProtection(eventId, orderShortId, enabled),

        onSuccess: (response, {eventId, orderShortId}) => {
            queryClient.setQueryData(
                [GET_ORDER_REFUND_PROTECTION_PUBLIC_QUERY_KEY, eventId, orderShortId],
                response.data ?? null
            );

            return Promise.all([
                queryClient.invalidateQueries({queryKey: [GET_ORDER_PUBLIC_QUERY_KEY, eventId, orderShortId]}),
                queryClient.invalidateQueries({queryKey: [GET_INITIATE_STRIPE_SESSION_PUBLIC_QUERY_KEY, eventId, orderShortId]}),
            ]);
        },
    });
};
