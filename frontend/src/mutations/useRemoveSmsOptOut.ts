import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";
import {GET_SMS_OPT_OUTS_QUERY_KEY} from "../queries/useGetSmsOptOuts.ts";
import {GET_SMS_CONVERSATION_QUERY_KEY} from "../queries/useGetSmsConversation.ts";

export const useRemoveSmsOptOut = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, optOutId}: { organizerId: IdParam, optOutId: IdParam }) =>
            smsClient.removeOptOut(organizerId, optOutId),

        onSuccess: (_, variables) => Promise.all([
            queryClient.invalidateQueries({queryKey: [GET_SMS_OPT_OUTS_QUERY_KEY, variables.organizerId]}),
            queryClient.invalidateQueries({queryKey: [GET_SMS_CONVERSATION_QUERY_KEY, variables.organizerId]}),
        ]),
    });
};
