import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";
import {GET_SMS_REPLY_TEMPLATES_QUERY_KEY} from "../queries/useGetSmsReplyTemplates.ts";

export const useDeleteSmsReplyTemplate = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, templateId}: { organizerId: IdParam, templateId: IdParam }) =>
            smsClient.deleteReplyTemplate(organizerId, templateId),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_SMS_REPLY_TEMPLATES_QUERY_KEY, variables.organizerId],
        }),
    });
};
