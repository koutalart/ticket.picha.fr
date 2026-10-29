import {api} from "./client";
import {
    GenericDataResponse,
    GenericPaginatedResponse,
    IdParam,
    OrganizerSmsSettings,
    SmsCampaign,
    SmsCampaignRecipient,
    SmsAssistantPreview,
    SmsConnectionTestResult,
    SmsConversation,
    SmsConversationDetail,
    SmsConversationFilter,
    SmsConversationStatus,
    SmsMessage,
    SmsOptOut,
    SmsReminderPreview,
    SmsReplyTemplate,
    OrganizerTeamContact,
    LiveChatStyle,
    OrganizerTeamContactRole,
} from "../types";

export interface SmsSettingsRequest {
    api_key?: string | null;
    from_number?: string | null;
    notify_on_inbound_sms?: boolean;
    assistant_enabled?: boolean;
    assistant_instructions?: string | null;
    assistant_auto_send?: boolean;
    assistant_holding_reply?: string | null;
    assistant_holding_reply_enabled?: boolean;
    live_chat_enabled?: boolean;
    live_chat_relay_label?: string | null;
    live_chat_style?: LiveChatStyle;
    checkin_welcome_sms_enabled?: boolean;
    sms_default_reminders_enabled?: boolean;
    organizer_assistant_enabled?: boolean;
    organizer_assistant_instructions?: string | null;
}

export interface OrganizerTeamContactRequest {
    phone: string;
    name?: string | null;
    role: OrganizerTeamContactRole;
}

export interface SmsConversationsParams {
    query?: string;
    filter?: SmsConversationFilter;
    page?: number;
}

export interface SmsReplyTemplateRequest {
    name: string;
    content: string;
}

export interface SmsReminderRequest {
    event_id: IdParam;
    message: string;
    send_at?: string | null;
    name?: string | null;
}

export interface SmsReminderUpdateRequest {
    message: string;
    send_at: string;
    name?: string | null;
}

export interface SmsOptOutsParams {
    query?: string;
    page?: number;
}

export const smsClient = {
    campaigns: async (organizerId: IdParam) => {
        const response = await api.get<GenericDataResponse<SmsCampaign[]>>(`organizers/${organizerId}/sms/campaigns`);
        return response.data;
    },
    campaignRecipients: async (organizerId: IdParam, campaignId: IdParam) => {
        const response = await api.get<GenericDataResponse<SmsCampaignRecipient[]>>(
            `organizers/${organizerId}/sms/campaigns/${campaignId}/recipients`,
        );
        return response.data;
    },
    reminders: async (organizerId: IdParam) => {
        const response = await api.get<GenericDataResponse<SmsCampaign[]>>(`organizers/${organizerId}/sms/reminders`);
        return response.data;
    },
    previewReminder: async (organizerId: IdParam, reminder: SmsReminderRequest) => {
        const response = await api.post<GenericDataResponse<SmsReminderPreview>>(`organizers/${organizerId}/sms/reminders/preview`, reminder);
        return response.data;
    },
    scheduleReminder: async (organizerId: IdParam, reminder: SmsReminderRequest) => {
        const response = await api.post<GenericDataResponse<SmsCampaign>>(`organizers/${organizerId}/sms/reminders`, reminder);
        return response.data;
    },
    updateReminder: async (organizerId: IdParam, reminderId: IdParam, reminder: SmsReminderUpdateRequest) => {
        const response = await api.put<GenericDataResponse<SmsCampaign>>(`organizers/${organizerId}/sms/reminders/${reminderId}`, reminder);
        return response.data;
    },
    cancelReminder: async (organizerId: IdParam, reminderId: IdParam) => {
        const response = await api.post<GenericDataResponse<SmsCampaign>>(`organizers/${organizerId}/sms/reminders/${reminderId}/cancel`);
        return response.data;
    },
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
    regenerateDraft: async (organizerId: IdParam, conversationId: IdParam) => {
        const response = await api.post<GenericDataResponse<SmsConversation>>(
            `organizers/${organizerId}/sms/conversations/${conversationId}/draft`,
        );
        return response.data;
    },
    previewAssistant: async (organizerId: IdParam, message: string, instructions: string | null) => {
        const response = await api.post<GenericDataResponse<SmsAssistantPreview>>(
            `organizers/${organizerId}/sms/assistant/preview`,
            {message, instructions},
        );
        return response.data;
    },
    importHistory: async (organizerId: IdParam) => {
        const response = await api.post<GenericDataResponse<OrganizerSmsSettings>>(`organizers/${organizerId}/sms/settings/import-history`);
        return response.data;
    },
    replyTemplates: async (organizerId: IdParam) => {
        const response = await api.get<GenericDataResponse<SmsReplyTemplate[]>>(`organizers/${organizerId}/sms/reply-templates`);
        return response.data;
    },
    createReplyTemplate: async (organizerId: IdParam, template: SmsReplyTemplateRequest) => {
        const response = await api.post<GenericDataResponse<SmsReplyTemplate>>(`organizers/${organizerId}/sms/reply-templates`, template);
        return response.data;
    },
    updateReplyTemplate: async (organizerId: IdParam, templateId: IdParam, template: SmsReplyTemplateRequest) => {
        const response = await api.put<GenericDataResponse<SmsReplyTemplate>>(`organizers/${organizerId}/sms/reply-templates/${templateId}`, template);
        return response.data;
    },
    deleteReplyTemplate: async (organizerId: IdParam, templateId: IdParam) => {
        await api.delete(`organizers/${organizerId}/sms/reply-templates/${templateId}`);
    },
    optOuts: async (organizerId: IdParam, params: SmsOptOutsParams) => {
        const response = await api.get<GenericPaginatedResponse<SmsOptOut>>(`organizers/${organizerId}/sms/opt-outs`, {params});
        return response.data;
    },
    addOptOut: async (organizerId: IdParam, phone: string) => {
        const response = await api.post<GenericDataResponse<SmsOptOut>>(`organizers/${organizerId}/sms/opt-outs`, {phone});
        return response.data;
    },
    removeOptOut: async (organizerId: IdParam, optOutId: IdParam) => {
        await api.delete(`organizers/${organizerId}/sms/opt-outs/${optOutId}`);
    },
    teamContacts: async (organizerId: IdParam) => {
        const response = await api.get<GenericDataResponse<OrganizerTeamContact[]>>(`organizers/${organizerId}/sms/team-contacts`);
        return response.data;
    },
    saveTeamContact: async (organizerId: IdParam, contact: OrganizerTeamContactRequest) => {
        const response = await api.post<GenericDataResponse<OrganizerTeamContact>>(`organizers/${organizerId}/sms/team-contacts`, contact);
        return response.data;
    },
    deleteTeamContact: async (organizerId: IdParam, contactId: IdParam) => {
        await api.delete(`organizers/${organizerId}/sms/team-contacts/${contactId}`);
    },
};
