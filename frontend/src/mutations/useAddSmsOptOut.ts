import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";
import {GET_SMS_OPT_OUTS_QUERY_KEY} from "../queries/useGetSmsOptOuts.ts";
import {GET_SMS_CONVERSATION_QUERY_KEY} from "../queries/useGetSmsConversation.ts";

export const useAddSmsOptOut = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, phone}: { organizerId: IdParam, phone: string }) =>
            smsClient.addOptOut(organizerId, phone),

        onSuccess: (_, variables) => Promise.all([
            queryClient.invalidateQueries({queryKey: [GET_SMS_OPT_OUTS_QUERY_KEY, variables.organizerId]}),
            queryClient.invalidateQueries({queryKey: [GET_SMS_CONVERSATION_QUERY_KEY, variables.organizerId]}),
        ]),
    });
};
