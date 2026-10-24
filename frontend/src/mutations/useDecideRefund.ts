import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam, RefundDecision} from "../types.ts";
import {refundApprovalClient} from "../api/live-chat.client.ts";
import {GET_REFUND_APPROVAL_QUERY_KEY} from "../queries/useGetRefundApproval.ts";

export const useDecideRefund = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({requestId, token, organizerId, decision}: {
            requestId: IdParam,
            token: string | null,
            organizerId?: IdParam,
            decision: RefundDecision,
        }) => token
            ? refundApprovalClient.decideWithLink(requestId, token, decision)
            : refundApprovalClient.decide(organizerId!, requestId, decision),

        onSettled: (_, __, variables) => queryClient.invalidateQueries({queryKey: [GET_REFUND_APPROVAL_QUERY_KEY, variables.requestId]}),
    });
};
