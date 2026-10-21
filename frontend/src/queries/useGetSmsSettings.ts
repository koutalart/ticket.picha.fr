import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";

export const GET_SMS_SETTINGS_QUERY_KEY = 'getSmsSettings';

export const useGetSmsSettings = (organizerId: IdParam, refetchInterval?: number) => {
    return useQuery({
        queryKey: [GET_SMS_SETTINGS_QUERY_KEY, organizerId],
        queryFn: async () => {
            const {data} = await smsClient.settings(organizerId);
            return data;
        },
        enabled: !!organizerId,
        refetchInterval: (query) => query.state.data?.history_import?.status === 'RUNNING' ? 3000 : (refetchInterval ?? false),
    });
};
