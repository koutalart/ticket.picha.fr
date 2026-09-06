import {useQuery} from "@tanstack/react-query";
import {boxOfficeClient, BoxOfficeContextEvent} from "../api/box-office.client.ts";
import {GenericDataResponse} from "../types.ts";

export const GET_BOX_OFFICE_CONTEXT_QUERY_KEY = 'getBoxOfficeContext';

export const useGetBoxOfficeContext = (enabled = true) => {
    return useQuery<GenericDataResponse<BoxOfficeContextEvent[]>>({
        queryKey: [GET_BOX_OFFICE_CONTEXT_QUERY_KEY],
        queryFn: () => boxOfficeClient.getContext(),
        enabled,
    });
};
