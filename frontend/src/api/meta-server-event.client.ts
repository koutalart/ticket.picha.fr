import {publicApi} from "./public-client";
import {IdParam} from "../types";

export type MetaPageEventName = 'PageView' | 'ViewContent';

export interface MetaPageEventRequest {
    event_name: MetaPageEventName;
    meta_event_id: string;
    consent: boolean;
    event_source_url?: string | null;
    fbp?: string | null;
    fbc?: string | null;
    fbclid?: string | null;
}

export const metaServerEventClientPublic = {
    track: (eventId: IdParam, payload: MetaPageEventRequest) => {
        return publicApi.post(`/events/${eventId}/page-signals`, payload);
    },
};
