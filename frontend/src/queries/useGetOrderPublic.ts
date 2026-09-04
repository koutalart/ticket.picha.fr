import {useQuery} from "@tanstack/react-query";
import {orderClientPublic} from "../api/order.client.ts";
import {IdParam, Order} from "../types.ts";
import {useMemo} from "react";
import {isSsr} from "../utilites/helpers.ts";
import {getStoredSessionIdentifier, storeSessionIdentifier} from "../utilites/checkoutSession.ts";

export const GET_ORDER_PUBLIC_QUERY_KEY = "getOrderPublic";

const resolveSessionIdentifier = (): string | null => {
    if (isSsr()) return null;

    const url = new URL(window.location.href);
    const fromUrl = url.searchParams.get("session_identifier");

    if (fromUrl) {
        storeSessionIdentifier(fromUrl);
        return fromUrl;
    }

    return getStoredSessionIdentifier();
};

export const useGetOrderPublic = (
    eventId: IdParam,
    orderShortId: IdParam,
    includes: string[] = []
) => {
    const sessionIdentifier = useMemo(resolveSessionIdentifier, []);

    return useQuery<Order>({
        queryKey: [
            GET_ORDER_PUBLIC_QUERY_KEY,
            eventId,
            orderShortId,
            sessionIdentifier,
        ],
        queryFn: async () => {
            const {data} = await orderClientPublic.findByShortId(
                Number(eventId),
                String(orderShortId),
                includes,
                sessionIdentifier ?? undefined
            );
            return data;
        },
        refetchOnWindowFocus: false,
        staleTime: 500,
        retryOnMount: false,
        retry: false,
    });
};
