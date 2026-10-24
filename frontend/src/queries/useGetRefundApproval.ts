import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {refundApprovalClient} from "../api/live-chat.client.ts";

export const GET_REFUND_APPROVAL_QUERY_KEY = 'getRefundApproval';

export const useGetRefundApproval = (requestId: IdParam, token: string | null, organizerId?: IdParam) => {
    return useQuery({
        queryKey: [GET_REFUND_APPROVAL_QUERY_KEY, requestId, token, organizerId],
        queryFn: async () => (token
            ? await refundApprovalClient.getWithLink(requestId, token)
            : await refundApprovalClient.get(organizerId!, requestId)).data,
        enabled: !!requestId && (!!token || !!organizerId),
        retry: false,
    });
};
