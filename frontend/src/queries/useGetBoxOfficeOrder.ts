import {useQuery} from "@tanstack/react-query";
import {boxOfficeClient, BoxOfficeOrderDetail} from "../api/box-office.client.ts";
import {GenericDataResponse, IdParam} from "../types.ts";

export const GET_BOX_OFFICE_ORDER_QUERY_KEY = 'getBoxOfficeOrder';

export const useGetBoxOfficeOrder = (eventId: IdParam | undefined, orderPublicId: string | null) => {
    return useQuery<GenericDataResponse<BoxOfficeOrderDetail>>({
        queryKey: [GET_BOX_OFFICE_ORDER_QUERY_KEY, eventId, orderPublicId],
        queryFn: () => boxOfficeClient.getOrder(eventId as IdParam, orderPublicId as string),
        enabled: !!eventId && !!orderPublicId,
    });
};
