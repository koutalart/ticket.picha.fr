import {api} from "./client.ts";
import {publicApi} from "./public-client.ts";
import {EventPages, GenericDataResponse, IdParam} from "../types.ts";

export const eventPagesClient = {
    get: async (eventId: IdParam) => {
        const response = await api.get<GenericDataResponse<EventPages>>(`events/${eventId}/pages`);
        return response.data;
    },
    update: async (eventId: IdParam, pages: EventPages) => {
        const response = await api.put<GenericDataResponse<EventPages>>(`events/${eventId}/pages`, pages);
        return response.data;
    },
};

export const eventPagesClientPublic = {
    get: async (eventId: IdParam) => {
        const response = await publicApi.get<GenericDataResponse<EventPages>>(`events/${eventId}/pages`);
        return response.data;
    },
};
