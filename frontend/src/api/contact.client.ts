import {api} from "./client";
import {GenericDataResponse, GenericPaginatedResponse, IdParam} from "../types";

export interface ContactTag {
    id: IdParam;
    name: string;
    contacts_count?: number;
}

export interface Contact {
    id: IdParam;
    organizer_id: IdParam;
    first_name?: string | null;
    last_name?: string | null;
    email?: string | null;
    phone?: string | null;
    public_code: string;
    source: 'ORDER' | 'ATTENDEE' | 'IMPORT' | 'MANUAL' | 'SMS_CAMPAIGN';
    email_consent: boolean;
    email_consent_at?: string | null;
    email_consent_source?: 'CHECKOUT' | 'IMPORT' | 'MANUAL' | null;
    email_unsubscribed_at?: string | null;
    sms_consent: boolean;
    sms_consent_at?: string | null;
    sms_consent_source?: 'CHECKOUT' | 'IMPORT' | 'MANUAL' | null;
    sms_unsubscribed_at?: string | null;
    orders_count: number;
    events_count: number;
    attended_events_count: number;
    total_spent: number;
    first_order_at?: string | null;
    last_order_at?: string | null;
    tags: ContactTag[];
    created_at: string;
}

export interface ContactSummary {
    total: number;
    email_consent: number;
    sms_consent: number;
    repeat_customers: number;
    total_spent: number;
}

export type ContactTimelineType = 'ORDER' | 'TICKET_RECEIVED' | 'CHECK_IN' | 'MESSAGE' | 'ANSWER';

export interface ContactTimelineItem {
    type: ContactTimelineType;
    occurred_at: string;
    event_id?: IdParam | null;
    event_title?: string | null;
    order_id?: IdParam | null;
    title?: string | null;
    detail?: string | null;
    amount?: number | null;
    currency?: string | null;
    status?: string | null;
}

export interface ContactNote {
    id: IdParam;
    note: string;
    author?: string | null;
    created_at: string;
}

export interface ContactFilters {
    query?: string;
    event_id?: string;
    tag_id?: string;
    email_consent?: boolean;
    sms_consent?: boolean;
    attended?: boolean;
    has_phone?: boolean;
    sort_by?: string;
    sort_direction?: 'asc' | 'desc';
    page?: number;
    per_page?: number;
}

export interface UpdateContactRequest {
    first_name?: string | null;
    last_name?: string | null;
    phone?: string | null;
    email_consent: boolean;
    sms_consent: boolean;
}

export type ContactImportField = 'FIRST_NAME' | 'LAST_NAME' | 'FULL_NAME' | 'EMAIL' | 'PHONE' | 'TAG' | 'IGNORE';

export interface ContactImportPreview {
    import_token: string;
    file_name: string;
    headers: string[];
    sample_rows: string[][];
    row_count: number;
    suggested_mapping: ContactImportField[];
}

export interface RunContactImportRequest {
    import_token: string;
    file_name: string;
    mapping: ContactImportField[];
    tag_name?: string | null;
    email_consent: boolean;
    sms_consent: boolean;
    default_country: 'FR' | 'YT' | 'RE' | 'MG' | 'DK';
    dry_run: boolean;
}

export interface ContactImportResult {
    import_id: IdParam | null;
    created_count: number;
    updated_count: number;
    rejected_count: number;
    rejected_rows: { line: number, reason: 'INVALID_EMAIL' | 'INVALID_PHONE' | 'NO_EMAIL_OR_PHONE', values: string[] }[];
    dry_run: boolean;
}

export interface CreateContactRequest extends UpdateContactRequest {
    email?: string | null;
}

export const buildContactQueryString = (filters: ContactFilters): string => {
    const params = new URLSearchParams();

    Object.entries(filters).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '' || value === false) {
            return;
        }
        params.set(key, value === true ? '1' : String(value));
    });

    const query = params.toString();
    return query ? `?${query}` : '';
};

export const contactClient = {
    all: async (organizerId: IdParam, filters: ContactFilters) => {
        const response = await api.get<GenericPaginatedResponse<Contact>>(
            `organizers/${organizerId}/contacts${buildContactQueryString(filters)}`
        );
        return response.data;
    },

    summary: async (organizerId: IdParam, filters: ContactFilters) => {
        const response = await api.get<GenericDataResponse<ContactSummary>>(
            `organizers/${organizerId}/contacts/summary${buildContactQueryString({...filters, page: undefined, sort_by: undefined, sort_direction: undefined})}`
        );
        return response.data.data;
    },

    export: async (organizerId: IdParam, filters: ContactFilters): Promise<Blob> => {
        const response = await api.get(
            `organizers/${organizerId}/contacts/export${buildContactQueryString({...filters, page: undefined})}`,
            {responseType: 'blob'}
        );
        return new Blob([response.data]);
    },

    get: async (organizerId: IdParam, contactId: IdParam) => {
        const response = await api.get<GenericDataResponse<Contact>>(`organizers/${organizerId}/contacts/${contactId}`);
        return response.data.data;
    },

    update: async (organizerId: IdParam, contactId: IdParam, data: UpdateContactRequest) => {
        const response = await api.put<GenericDataResponse<Contact>>(`organizers/${organizerId}/contacts/${contactId}`, data);
        return response.data.data;
    },

    timeline: async (organizerId: IdParam, contactId: IdParam) => {
        const response = await api.get<GenericDataResponse<ContactTimelineItem[]>>(
            `organizers/${organizerId}/contacts/${contactId}/timeline`
        );
        return response.data.data;
    },

    notes: async (organizerId: IdParam, contactId: IdParam) => {
        const response = await api.get<GenericDataResponse<ContactNote[]>>(`organizers/${organizerId}/contacts/${contactId}/notes`);
        return response.data.data;
    },

    createNote: async (organizerId: IdParam, contactId: IdParam, note: string) => {
        const response = await api.post<GenericDataResponse<ContactNote>>(
            `organizers/${organizerId}/contacts/${contactId}/notes`,
            {note}
        );
        return response.data.data;
    },

    deleteNote: async (organizerId: IdParam, contactId: IdParam, noteId: IdParam) => {
        await api.delete(`organizers/${organizerId}/contacts/${contactId}/notes/${noteId}`);
    },

    tags: async (organizerId: IdParam) => {
        const response = await api.get<GenericDataResponse<ContactTag[]>>(`organizers/${organizerId}/contacts/tags`);
        return response.data.data;
    },

    assignTag: async (organizerId: IdParam, name: string, contactIds: IdParam[]) => {
        const response = await api.post<GenericDataResponse<ContactTag>>(
            `organizers/${organizerId}/contacts/tags`,
            {name, contact_ids: contactIds}
        );
        return response.data.data;
    },

    create: async (organizerId: IdParam, data: CreateContactRequest) => {
        const response = await api.post<GenericDataResponse<Contact>>(`organizers/${organizerId}/contacts`, data);
        return response.data.data;
    },

    uploadImport: async (organizerId: IdParam, file: File) => {
        const formData = new FormData();
        formData.append('file', file);
        const response = await api.post<GenericDataResponse<ContactImportPreview>>(
            `organizers/${organizerId}/contacts/imports/upload`,
            formData,
            {headers: {'Content-Type': 'multipart/form-data'}}
        );
        return response.data.data;
    },

    runImport: async (organizerId: IdParam, data: RunContactImportRequest) => {
        const response = await api.post<GenericDataResponse<ContactImportResult>>(`organizers/${organizerId}/contacts/imports`, data);
        return response.data.data;
    },

    revertImport: async (organizerId: IdParam, importId: IdParam) => {
        const response = await api.delete<GenericDataResponse<{ deleted_count: number }>>(
            `organizers/${organizerId}/contacts/imports/${importId}`
        );
        return response.data.data;
    },

    removeTag: async (organizerId: IdParam, contactId: IdParam, tagId: IdParam) => {
        await api.delete(`organizers/${organizerId}/contacts/${contactId}/tags/${tagId}`);
    },
};
