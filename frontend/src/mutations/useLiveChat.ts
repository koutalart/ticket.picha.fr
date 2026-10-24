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
