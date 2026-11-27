import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam, LoyaltyProgram} from "../types.ts";
import {loyaltyClient} from "../api/loyalty.client.ts";
import {GET_LOYALTY_PROGRAM_QUERY_KEY} from "../queries/useGetLoyaltyProgram.ts";

export const useUpsertLoyaltyProgram = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, program}: { organizerId: IdParam, program: LoyaltyProgram }) =>
            loyaltyClient.upsertProgram(organizerId, program),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_LOYALTY_PROGRAM_QUERY_KEY, variables.organizerId],
        }),
    });
};
