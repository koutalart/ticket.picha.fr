import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient, SmsReminderUpdateRequest} from "../api/sms.client.ts";
import {GET_SMS_REMINDERS_QUERY_KEY} from "../queries/useGetSmsReminders.ts";

export const useUpdateSmsReminder = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, reminderId, reminder}: {
            organizerId: IdParam,
            reminderId: IdParam,
            reminder: SmsReminderUpdateRequest,
        }) => smsClient.updateReminder(organizerId, reminderId, reminder),

        onSettled: (_, __, variables) => queryClient.invalidateQueries({
            queryKey: [GET_SMS_REMINDERS_QUERY_KEY, variables.organizerId],
        }),
    });
};
