import {api} from "./client";
import {IdParam} from "../types.ts";

export interface ViewerEvent {
    id: IdParam;
    title: string;
    start_date?: string | null;
    timezone?: string | null;
}

export const viewerClient = {
    events: async (): Promise<ViewerEvent[]> => {
        const response = await api.get<{ data: ViewerEvent[] }>('viewer/events');
        return response.data.data;
    },
};
