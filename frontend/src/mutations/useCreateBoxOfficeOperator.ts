import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {boxOfficeClient, CreateBoxOfficeOperatorRequest} from "../api/box-office.client.ts";
import {GET_BOX_OFFICE_OPERATORS_QUERY_KEY} from "../queries/useGetBoxOfficeOperators.ts";

export const useCreateBoxOfficeOperator = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, operator}: {
            eventId: IdParam,
            operator: CreateBoxOfficeOperatorRequest,
        }) => boxOfficeClient.createOperator(eventId, operator),

        onSuccess: (_, variables) => {
            return queryClient.invalidateQueries({
                queryKey: [GET_BOX_OFFICE_OPERATORS_QUERY_KEY, variables.eventId],
            });
        },
    });
};
