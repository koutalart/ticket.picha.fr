import {useQuery} from "@tanstack/react-query";
import {boxOfficeClient, BoxOfficeProduct} from "../api/box-office.client.ts";
import {GenericDataResponse, IdParam} from "../types.ts";

export const GET_BOX_OFFICE_PRODUCTS_QUERY_KEY = 'getBoxOfficeProducts';

export const useGetBoxOfficeProducts = (eventId: IdParam | undefined) => {
    return useQuery<GenericDataResponse<BoxOfficeProduct[]>>({
        queryKey: [GET_BOX_OFFICE_PRODUCTS_QUERY_KEY, eventId],
        queryFn: () => boxOfficeClient.getProducts(eventId as IdParam),
        enabled: eventId !== undefined && eventId !== null && eventId !== '',
    });
};
