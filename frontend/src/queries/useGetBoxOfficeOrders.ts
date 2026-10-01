import {useInfiniteQuery} from "@tanstack/react-query";
import {boxOfficeClient, BoxOfficeOrderFilters} from "../api/box-office.client.ts";
import {IdParam} from "../types.ts";

export const GET_BOX_OFFICE_ORDERS_QUERY_KEY = 'getBoxOfficeOrders';

export const useGetBoxOfficeOrders = (eventId: IdParam | undefined, filters: BoxOfficeOrderFilters) => {
    return useInfiniteQuery({
        queryKey: [GET_BOX_OFFICE_ORDERS_QUERY_KEY, eventId, filters],
        queryFn: ({pageParam}) => boxOfficeClient.getOrders(eventId as IdParam, filters, pageParam),
        initialPageParam: 1,
        getNextPageParam: (lastPage) => lastPage.meta.current_page < lastPage.meta.last_page
            ? lastPage.meta.current_page + 1
            : undefined,
        enabled: !!eventId,
        placeholderData: (previous) => previous,
        refetchInterval: 30000,
    });
};
