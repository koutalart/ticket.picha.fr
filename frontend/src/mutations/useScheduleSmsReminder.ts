import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient, SmsReminderRequest} from "../api/sms.client.ts";
import {GET_SMS_REMINDERS_QUERY_KEY} from "../queries/useGetSmsReminders.ts";

export const useScheduleSmsReminder = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, reminder}: { organizerId: IdParam, reminder: SmsReminderRequest }) =>
            smsClient.scheduleReminder(organizerId, reminder),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_SMS_REMINDERS_QUERY_KEY, variables.organizerId],
        }),
    });
};
