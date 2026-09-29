import {useQuery} from "@tanstack/react-query";
import {boxOfficeClient, BoxOfficeOperator} from "../api/box-office.client.ts";
import {GenericDataResponse, IdParam} from "../types.ts";

export const GET_BOX_OFFICE_OPERATORS_QUERY_KEY = 'getBoxOfficeOperators';

export const useGetBoxOfficeOperators = (eventId: IdParam | undefined, enabled = true) => {
    return useQuery<GenericDataResponse<BoxOfficeOperator[]>>({
        queryKey: [GET_BOX_OFFICE_OPERATORS_QUERY_KEY, eventId],
        queryFn: () => boxOfficeClient.getOperators(eventId as IdParam),
        enabled: enabled && eventId !== undefined && eventId !== null && eventId !== '',
    });
};
