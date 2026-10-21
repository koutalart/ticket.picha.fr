import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";
import {GET_SMS_CONVERSATION_QUERY_KEY} from "../queries/useGetSmsConversation.ts";
import {GET_SMS_CONVERSATIONS_QUERY_KEY} from "../queries/useGetSmsConversations.ts";

export const useSendSmsReply = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, conversationId, content}: {
            organizerId: IdParam,
            conversationId: IdParam,
            content: string
        }) => smsClient.reply(organizerId, conversationId, content),

        onSettled: (_, __, variables) => Promise.all([
            queryClient.invalidateQueries({queryKey: [GET_SMS_CONVERSATION_QUERY_KEY, variables.organizerId, variables.conversationId]}),
            queryClient.invalidateQueries({queryKey: [GET_SMS_CONVERSATIONS_QUERY_KEY, variables.organizerId]}),
        ]),
    });
};
