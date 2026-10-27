import {useQuery} from "@tanstack/react-query";
import {orderClientPublic} from "../api/order.client.ts";
import {IdParam, RefundProtectionOffer} from "../types.ts";

export const GET_ORDER_REFUND_PROTECTION_PUBLIC_QUERY_KEY = 'getOrderRefundProtectionPublic';

export const useGetOrderRefundProtectionPublic = (eventId: IdParam, orderShortId: IdParam, enabled = true) => {
    return useQuery<RefundProtectionOffer | null>({
        queryKey: [GET_ORDER_REFUND_PROTECTION_PUBLIC_QUERY_KEY, eventId, orderShortId],
        queryFn: async () => {
            const {data} = await orderClientPublic.getRefundProtection(eventId, orderShortId);
            return data ?? null;
        },
        enabled: enabled && !!eventId && !!orderShortId,
        refetchOnWindowFocus: false,
        retry: false,
    });
};
