import {api} from "./client";
import {
    GenericDataResponse,
    GenericPaginatedResponse,
    IdParam,
    OrganizerSmsSettings,
    SmsConnectionTestResult,
    SmsConversation,
    SmsConversationDetail,
    SmsConversationFilter,
    SmsConversationStatus,
    SmsMessage,
} from "../types";

export interface SmsSettingsRequest {
    api_key?: string | null;
    from_number?: string | null;
}

export interface SmsConversationsParams {
    query?: string;
    filter?: SmsConversationFilter;
    page?: number;
}

export const smsClient = {
    settings: async (organizerId: IdParam) => {
        const response = await api.get<GenericDataResponse<OrganizerSmsSettings>>(`organizers/${organizerId}/sms/settings`);
        return response.data;
    },
    updateSettings: async (organizerId: IdParam, settings: SmsSettingsRequest) => {
        const response = await api.put<GenericDataResponse<OrganizerSmsSettings>>(`organizers/${organizerId}/sms/settings`, settings);
        return response.data;
    },
    regenerateSigningKey: async (organizerId: IdParam) => {
        const response = await api.post<GenericDataResponse<OrganizerSmsSettings>>(`organizers/${organizerId}/sms/settings/regenerate-signing-key`);
        return response.data;
    },
    testConnection: async (organizerId: IdParam) => {
        const response = await api.post<GenericDataResponse<SmsConnectionTestResult>>(`organizers/${organizerId}/sms/settings/test`);
        return response.data;
    },
    conversations: async (organizerId: IdParam, params: SmsConversationsParams) => {
        const response = await api.get<GenericPaginatedResponse<SmsConversation> & {meta: {unread_conversations: number}}>(
            `organizers/${organizerId}/sms/conversations`,
            {params},
        );
        return response.data;
    },
    unreadCount: async (organizerId: IdParam) => {
        const response = await api.get<GenericDataResponse<{unread_conversations: number}>>(`organizers/${organizerId}/sms/unread-count`);
        return response.data;
    },
    conversation: async (organizerId: IdParam, conversationId: IdParam) => {
        const response = await api.get<GenericDataResponse<SmsConversationDetail>>(`organizers/${organizerId}/sms/conversations/${conversationId}`);
        return response.data;
    },
    updateConversation: async (organizerId: IdParam, conversationId: IdParam, status: SmsConversationStatus) => {
        const response = await api.patch<GenericDataResponse<SmsConversation>>(
            `organizers/${organizerId}/sms/conversations/${conversationId}`,
            {status},
        );
        return response.data;
    },
    reply: async (organizerId: IdParam, conversationId: IdParam, content: string) => {
        const response = await api.post<GenericDataResponse<SmsMessage>>(
            `organizers/${organizerId}/sms/conversations/${conversationId}/messages`,
            {content},
        );
        return response.data;
    },
};
