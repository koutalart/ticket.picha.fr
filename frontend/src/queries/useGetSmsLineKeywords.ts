import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";

export const GET_SMS_LINE_KEYWORDS_QUERY_KEY = 'getSmsLineKeywords';

export const useGetSmsLineKeywords = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_SMS_LINE_KEYWORDS_QUERY_KEY, organizerId],
        queryFn: async () => (await smsClient.lineKeywords(organizerId)).data,
        enabled: !!organizerId,
    });
};
