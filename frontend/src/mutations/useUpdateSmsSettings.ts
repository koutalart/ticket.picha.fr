import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient, SmsSettingsRequest} from "../api/sms.client.ts";
import {GET_SMS_SETTINGS_QUERY_KEY} from "../queries/useGetSmsSettings.ts";

export const useUpdateSmsSettings = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, settings}: { organizerId: IdParam, settings: SmsSettingsRequest }) =>
            smsClient.updateSettings(organizerId, settings),

        onSuccess: ({data}, variables) => queryClient.setQueryData(
            [GET_SMS_SETTINGS_QUERY_KEY, variables.organizerId],
            data,
        ),
    });
};
