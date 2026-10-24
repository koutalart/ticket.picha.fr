import {api} from "./client";
import {EventDailyReport, GenericDataResponse, IdParam} from "../types";

export interface EventDailyReportRequest {
    enabled?: boolean;
    send_time?: string;
    copy_supervisors?: boolean;
}

export const eventDailyReportClient = {
    get: async (eventId: IdParam) => {
        const response = await api.get<GenericDataResponse<EventDailyReport>>(`events/${eventId}/daily-report`);
        return response.data;
    },
    update: async (eventId: IdParam, report: EventDailyReportRequest) => {
        const response = await api.put<GenericDataResponse<EventDailyReport>>(`events/${eventId}/daily-report`, report);
        return response.data;
    },
};
