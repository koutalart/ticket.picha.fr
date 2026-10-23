import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";

export const GET_SMS_REPLY_TEMPLATES_QUERY_KEY = 'getSmsReplyTemplates';

export const useGetSmsReplyTemplates = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_SMS_REPLY_TEMPLATES_QUERY_KEY, organizerId],
        queryFn: async () => {
            const {data} = await smsClient.replyTemplates(organizerId);
            return data;
        },
        enabled: !!organizerId,
    });
};
