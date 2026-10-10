import {api} from "./client.ts";
import {Event, GenericDataResponse, IdParam} from "../types.ts";

export interface EventDraft {
    title: string | null;
    start_date: string | null;
    end_date: string | null;
    venue: string | null;
    description: string | null;
    price: number | null;
    category: string | null;
    quantity: number | null;
    facts: string | null;
}

export interface ResearchDescriptionRequest {
    title: string;
    venue?: string | null;
    category?: string | null;
    facts?: string | null;
    draft?: string | null;
}

export interface CreateEventFromAssistantRequest {
    organizer_id: IdParam;
    title: string;
    category?: string | null;
    start_date: string;
    end_date?: string | null;
    venue?: string | null;
    description?: string | null;
    price: number;
    online_quantity?: number | null;
    box_office_quantity?: number | null;
}

export const eventAssistantClient = {
    status: async () => {
        const response = await api.get<GenericDataResponse<{ ai_enabled: boolean }>>('events/assistant');
        return response.data;
    },
    readPoster: async (poster: File, timezone?: string) => {
        const formData = new FormData();
        formData.append('image', poster);
        if (timezone) {
            formData.append('timezone', timezone);
        }
        // The api client defaults to JSON, which would turn the file into "{}": keep multipart here.
        const response = await api.post<GenericDataResponse<EventDraft>>('events/assistant/poster', formData, {
            headers: {'Content-Type': 'multipart/form-data'},
        });
        return response.data;
    },
    improveDescription: async (payload: { description: string, title?: string, venue?: string }) => {
        const response = await api.post<GenericDataResponse<{ description: string }>>('events/assistant/description', payload);
        return response.data;
    },
    researchDescription: async (payload: ResearchDescriptionRequest) => {
        const response = await api.post<GenericDataResponse<{ description: string }>>('events/assistant/description/research', payload);
        return response.data;
    },
    create: async (payload: CreateEventFromAssistantRequest) => {
        const response = await api.post<GenericDataResponse<Event>>('events/assistant/create', payload);
        return response.data;
    },
};
