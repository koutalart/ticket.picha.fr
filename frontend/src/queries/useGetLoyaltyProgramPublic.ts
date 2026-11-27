import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {loyaltyClientPublic} from "../api/loyalty.client.ts";

export const GET_LOYALTY_PROGRAM_PUBLIC_QUERY_KEY = 'getLoyaltyProgramPublic';

export const useGetLoyaltyProgramPublic = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_LOYALTY_PROGRAM_PUBLIC_QUERY_KEY, organizerId],
        queryFn: async () => {
            const {data} = await loyaltyClientPublic.getProgram(organizerId);
            return data;
        },
        enabled: !!organizerId,
        retry: false,
    });
};
