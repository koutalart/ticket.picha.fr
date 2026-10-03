import {publicApi} from "./public-client.ts";
import {GenericDataResponse} from "../types.ts";

export type DemoRequestEventType =
    | "CONFERENCE"
    | "SEMINAR"
    | "GENERAL_ASSEMBLY"
    | "TRADE_SHOW"
    | "CEREMONY"
    | "INTERNAL_EVENT"
    | "OTHER";

export type DemoRequestAttendeeCount = "<50" | "50-150" | "150-500" | "500-1000" | ">1000";

export interface DemoRequest {
    first_name: string;
    last_name: string;
    email: string;
    organization: string;
    event_type: DemoRequestEventType | "";
    event_date: string;
    attendee_count: DemoRequestAttendeeCount | "";
    website: string;
}

export const demoRequestClient = {
    send: async (demoRequest: DemoRequest) => {
        const response = await publicApi.post<GenericDataResponse<{ message: string }>>('demo-requests', {
            ...demoRequest,
            event_date: demoRequest.event_date || null,
        });
        return response.data;
    },
};
