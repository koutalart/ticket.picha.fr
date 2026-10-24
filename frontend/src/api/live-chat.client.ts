import {api} from "./client";
import {publicApi} from "./public-client";
import {
    GenericDataResponse,
    GenericPaginatedResponse,
    IdParam,
    LiveChatConfig,
    LiveChatRefundStep,
    LiveChatSession,
    LiveChatTurn,
    RefundApproval,
    RefundDecision,
} from "../types";

// The chat session id is opaque and travels in this header, never in a URL.
const SESSION_HEADER = 'X-Chat-Session';

const sessionHeaders = (sessionId: string) => ({headers: {[SESSION_HEADER]: sessionId}});

export const liveChatPublicClient = {
    config: async (organizerId: IdParam) => {
        const response = await publicApi.get<GenericDataResponse<LiveChatConfig>>(`organizers/${organizerId}/live-chat`);
        return response.data;
    },
    start: async (organizerId: IdParam, eventId?: IdParam | null) => {
        const response = await publicApi.post<GenericDataResponse<LiveChatTurn>>(
            `organizers/${organizerId}/live-chat/sessions`,
            {event_id: eventId ?? null},
        );
        return response.data;
    },
    messages: async (sessionId: string) => {
        const response = await publicApi.get<GenericDataResponse<LiveChatTurn>>('live-chat/messages', sessionHeaders(sessionId));
        return response.data;
    },
    post: async (sessionId: string, message: string) => {
        const response = await publicApi.post<GenericDataResponse<LiveChatTurn>>(
            'live-chat/messages',
            {message},
            sessionHeaders(sessionId),
        );
        return response.data;
    },
    contact: async (sessionId: string, contact: { phone?: string | null; email?: string | null }) => {
        const response = await publicApi.post<GenericDataResponse<LiveChatTurn>>(
            'live-chat/contact',
            contact,
            sessionHeaders(sessionId),
        );
        return response.data;
    },
    refundRequest: async (sessionId: string, orderReference: string, email: string) => {
        const response = await publicApi.post<GenericDataResponse<LiveChatRefundStep>>(
            'live-chat/refund/request',
            {order_reference: orderReference, email},
            sessionHeaders(sessionId),
        );
        return response.data;
    },
    refundVerify: async (sessionId: string, code: string) => {
        const response = await publicApi.post<GenericDataResponse<LiveChatRefundStep>>(
            'live-chat/refund/verify',
            {code},
            sessionHeaders(sessionId),
        );
        return response.data;
    },
    // Only the ticket ids are sent: the amount is always computed by the server.
    refundConfirm: async (sessionId: string, attendeeIds: number[]) => {
        const response = await publicApi.post<GenericDataResponse<LiveChatRefundStep>>(
            'live-chat/refund/confirm',
            {attendee_ids: attendeeIds},
            sessionHeaders(sessionId),
        );
        return response.data;
    },
};

export const liveChatClient = {
    list: async (organizerId: IdParam, page = 1) => {
        const response = await api.get<GenericPaginatedResponse<LiveChatSession>>(
            `organizers/${organizerId}/live-chats?page=${page}`,
        );
        return response.data;
    },
    get: async (organizerId: IdParam, sessionId: IdParam) => {
        const response = await api.get<GenericDataResponse<LiveChatSession>>(`organizers/${organizerId}/live-chats/${sessionId}`);
        return response.data;
    },
};

export const refundApprovalClient = {
    getWithLink: async (requestId: IdParam, token: string) => {
        const response = await publicApi.get<GenericDataResponse<RefundApproval>>(`refund-approvals/${requestId}`, {params: {token}});
        return response.data;
    },
    decideWithLink: async (requestId: IdParam, token: string, decision: RefundDecision) => {
        const response = await publicApi.post<GenericDataResponse<RefundApproval>>(`refund-approvals/${requestId}/decision`, {token, decision});
        return response.data;
    },
    get: async (organizerId: IdParam, requestId: IdParam) => {
        const response = await api.get<GenericDataResponse<RefundApproval>>(`organizers/${organizerId}/refund-approvals/${requestId}`);
        return response.data;
    },
    decide: async (organizerId: IdParam, requestId: IdParam, decision: RefundDecision) => {
        const response = await api.post<GenericDataResponse<RefundApproval>>(`organizers/${organizerId}/refund-approvals/${requestId}/decision`, {decision});
        return response.data;
    },
};
