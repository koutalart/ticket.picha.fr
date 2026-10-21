import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient, SmsReminderRequest} from "../api/sms.client.ts";

export const usePreviewSmsReminder = () => {
    return useMutation({
        mutationFn: ({organizerId, reminder}: { organizerId: IdParam, reminder: SmsReminderRequest }) =>
            smsClient.previewReminder(organizerId, reminder),
    });
};
