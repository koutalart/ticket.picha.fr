import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam, SmsConversationStatus} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";
import {GET_SMS_CONVERSATION_QUERY_KEY} from "../queries/useGetSmsConversation.ts";
import {GET_SMS_CONVERSATIONS_QUERY_KEY} from "../queries/useGetSmsConversations.ts";

export const useUpdateSmsConversation = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, conversationId, status}: {
            organizerId: IdParam,
            conversationId: IdParam,
            status: SmsConversationStatus
        }) => smsClient.updateConversation(organizerId, conversationId, status),

        onSuccess: (_, variables) => Promise.all([
            queryClient.invalidateQueries({queryKey: [GET_SMS_CONVERSATION_QUERY_KEY, variables.organizerId, variables.conversationId]}),
            queryClient.invalidateQueries({queryKey: [GET_SMS_CONVERSATIONS_QUERY_KEY, variables.organizerId]}),
        ]),
    });
};
