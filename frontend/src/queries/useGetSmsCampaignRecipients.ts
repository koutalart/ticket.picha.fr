import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";

export const GET_SMS_CAMPAIGN_RECIPIENTS_QUERY_KEY = 'getSmsCampaignRecipients';

export const useGetSmsCampaignRecipients = (organizerId: IdParam, campaignId?: IdParam) => {
    return useQuery({
        queryKey: [GET_SMS_CAMPAIGN_RECIPIENTS_QUERY_KEY, organizerId, campaignId],
        queryFn: async () => {
            const {data} = await smsClient.campaignRecipients(organizerId, campaignId!);
            return data;
        },
        enabled: !!organizerId && !!campaignId,
    });
};
