import {api} from "./client";
import {
    EmailMessage,
    EmailRecipient,
    EmailThread,
    EmailThreadDetail,
    EmailThreadFilter,
    EmailThreadStatus,
    GenericDataResponse,
    GenericPaginatedResponse,
    IdParam,
} from "../types";

export interface EmailThreadsParams {
    query?: string;
    filter?: EmailThreadFilter;
    page?: number;
}

export interface EmailThreadsMeta {
    unread_threads: number;
    reply_tracking_enabled: boolean;
}

export interface NewEmailRequest {
    email: string;
    subject: string;
    body: string;
}

export const emailInboxClient = {
    threads: async (organizerId: IdParam, params: EmailThreadsParams) => {
        const response = await api.get<GenericPaginatedResponse<EmailThread> & {meta: EmailThreadsMeta}>(
            `organizers/${organizerId}/email/threads`,
            {params},
        );
        return response.data;
    },
    unreadCount: async (organizerId: IdParam) => {
        const response = await api.get<GenericDataResponse<{unread_threads: number}>>(`organizers/${organizerId}/email/unread-count`);
        return response.data;
    },
    thread: async (organizerId: IdParam, threadId: IdParam) => {
        const response = await api.get<GenericDataResponse<EmailThreadDetail>>(`organizers/${organizerId}/email/threads/${threadId}`);
        return response.data;
    },
    updateThread: async (organizerId: IdParam, threadId: IdParam, status: EmailThreadStatus) => {
        const response = await api.patch<GenericDataResponse<EmailThread>>(
            `organizers/${organizerId}/email/threads/${threadId}`,
            {status},
        );
        return response.data;
    },
    reply: async (organizerId: IdParam, threadId: IdParam, body: string, subject?: string) => {
        const response = await api.post<GenericDataResponse<EmailMessage>>(
            `organizers/${organizerId}/email/threads/${threadId}/messages`,
            {body, subject: subject || null},
        );
        return response.data;
    },
    send: async (organizerId: IdParam, request: NewEmailRequest) => {
        const response = await api.post<GenericDataResponse<EmailMessage>>(
            `organizers/${organizerId}/email/messages`,
            request,
        );
        return response.data;
    },
    recipients: async (organizerId: IdParam, query: string) => {
        const response = await api.get<GenericDataResponse<EmailRecipient[]>>(
            `organizers/${organizerId}/email/recipients`,
            {params: {query}},
        );
        return response.data;
    },
};
