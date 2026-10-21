import {keepPreviousData, useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient, SmsOptOutsParams} from "../api/sms.client.ts";

export const GET_SMS_OPT_OUTS_QUERY_KEY = 'getSmsOptOuts';

export const useGetSmsOptOuts = (organizerId: IdParam, params: SmsOptOutsParams) => {
    return useQuery({
        queryKey: [GET_SMS_OPT_OUTS_QUERY_KEY, organizerId, params],
        queryFn: () => smsClient.optOuts(organizerId, params),
        enabled: !!organizerId,
        placeholderData: keepPreviousData,
    });
};
