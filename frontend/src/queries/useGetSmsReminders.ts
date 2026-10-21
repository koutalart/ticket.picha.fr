import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";

export const GET_SMS_REMINDERS_QUERY_KEY = 'getSmsReminders';

const REFRESH_WHILE_SENDING_MS = 30000;

export const useGetSmsReminders = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_SMS_REMINDERS_QUERY_KEY, organizerId],
        queryFn: async () => {
            const {data} = await smsClient.reminders(organizerId);
            return data;
        },
        enabled: !!organizerId,
        refetchInterval: (query) => (query.state.data ?? []).some(reminder => reminder.status === 'PROCESSING')
            ? REFRESH_WHILE_SENDING_MS
            : false,
    });
};
