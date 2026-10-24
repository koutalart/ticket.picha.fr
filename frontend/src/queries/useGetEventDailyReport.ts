import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {eventDailyReportClient} from "../api/event-daily-report.client.ts";

export const GET_EVENT_DAILY_REPORT_QUERY_KEY = 'getEventDailyReport';

export const useGetEventDailyReport = (eventId: IdParam) => {
    return useQuery({
        queryKey: [GET_EVENT_DAILY_REPORT_QUERY_KEY, eventId],
        queryFn: async () => (await eventDailyReportClient.get(eventId)).data,
        enabled: !!eventId,
    });
};
