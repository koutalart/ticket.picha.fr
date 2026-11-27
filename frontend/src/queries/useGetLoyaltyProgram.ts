import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {loyaltyClient} from "../api/loyalty.client.ts";

export const GET_LOYALTY_PROGRAM_QUERY_KEY = 'getLoyaltyProgram';

export const useGetLoyaltyProgram = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_LOYALTY_PROGRAM_QUERY_KEY, organizerId],
        queryFn: async () => {
            const {data} = await loyaltyClient.getProgram(organizerId);
            return data;
        },
        enabled: !!organizerId,
    });
};
