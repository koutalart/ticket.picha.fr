import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient, SmsReplyTemplateRequest} from "../api/sms.client.ts";
import {GET_SMS_REPLY_TEMPLATES_QUERY_KEY} from "../queries/useGetSmsReplyTemplates.ts";

export const useSaveSmsReplyTemplate = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, templateId, template}: {
            organizerId: IdParam,
            templateId?: IdParam,
            template: SmsReplyTemplateRequest,
        }) => templateId
            ? smsClient.updateReplyTemplate(organizerId, templateId, template)
            : smsClient.createReplyTemplate(organizerId, template),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_SMS_REPLY_TEMPLATES_QUERY_KEY, variables.organizerId],
        }),
    });
};
