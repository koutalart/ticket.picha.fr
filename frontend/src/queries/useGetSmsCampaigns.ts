import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";

export const GET_SMS_CAMPAIGNS_QUERY_KEY = 'getSmsCampaigns';

export const useGetSmsCampaigns = (organizerId: IdParam, enabled = true) => {
    return useQuery({
        queryKey: [GET_SMS_CAMPAIGNS_QUERY_KEY, organizerId],
        queryFn: async () => {
            const {data} = await smsClient.campaigns(organizerId);
            return data;
        },
        enabled: !!organizerId && enabled,
    });
};
