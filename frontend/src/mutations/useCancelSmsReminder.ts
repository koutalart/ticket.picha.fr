import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";
import {GET_SMS_REMINDERS_QUERY_KEY} from "../queries/useGetSmsReminders.ts";

export const useCancelSmsReminder = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, reminderId}: { organizerId: IdParam, reminderId: IdParam }) =>
            smsClient.cancelReminder(organizerId, reminderId),

        onSettled: (_, __, variables) => queryClient.invalidateQueries({
            queryKey: [GET_SMS_REMINDERS_QUERY_KEY, variables.organizerId],
        }),
    });
};
