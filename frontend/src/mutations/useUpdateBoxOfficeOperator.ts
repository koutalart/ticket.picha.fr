import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {boxOfficeClient, BoxOfficeOperatorStatus} from "../api/box-office.client.ts";
import {GET_BOX_OFFICE_OPERATORS_QUERY_KEY} from "../queries/useGetBoxOfficeOperators.ts";

export const useUpdateBoxOfficeOperator = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, userId, status}: {
            eventId: IdParam,
            userId: IdParam,
            status: BoxOfficeOperatorStatus,
        }) => boxOfficeClient.updateOperator(eventId, userId, status),

        onSuccess: (_, variables) => {
            return queryClient.invalidateQueries({
                queryKey: [GET_BOX_OFFICE_OPERATORS_QUERY_KEY, variables.eventId],
            });
        },
    });
};
