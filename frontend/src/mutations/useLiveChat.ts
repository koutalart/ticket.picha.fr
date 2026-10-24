import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {liveChatPublicClient} from "../api/live-chat.client.ts";

export const useStartLiveChat = () => {
    return useMutation({
        mutationFn: ({organizerId, eventId}: { organizerId: IdParam; eventId?: IdParam | null }) =>
            liveChatPublicClient.start(organizerId, eventId),
    });
};

export const usePostLiveChatMessage = () => {
    return useMutation({
        mutationFn: ({sessionId, message}: { sessionId: string; message: string }) =>
            liveChatPublicClient.post(sessionId, message),
    });
};

export const useSubmitLiveChatContact = () => {
    return useMutation({
        mutationFn: ({sessionId, phone, email}: { sessionId: string; phone?: string | null; email?: string | null }) =>
            liveChatPublicClient.contact(sessionId, {phone, email}),
    });
};

export const useRequestLiveChatRefundCode = () => {
    return useMutation({
        mutationFn: ({sessionId, orderReference, email}: { sessionId: string; orderReference: string; email: string }) =>
            liveChatPublicClient.refundRequest(sessionId, orderReference, email),
    });
};

export const useVerifyLiveChatRefundCode = () => {
    return useMutation({
        mutationFn: ({sessionId, code}: { sessionId: string; code: string }) =>
            liveChatPublicClient.refundVerify(sessionId, code),
    });
};

export const useConfirmLiveChatRefund = () => {
    return useMutation({
        mutationFn: ({sessionId, attendeeIds}: { sessionId: string; attendeeIds: number[] }) =>
            liveChatPublicClient.refundConfirm(sessionId, attendeeIds),
    });
};
