import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {eventDailyReportClient, EventDailyReportRequest} from "../api/event-daily-report.client.ts";
import {GET_EVENT_DAILY_REPORT_QUERY_KEY} from "../queries/useGetEventDailyReport.ts";

export const useUpdateEventDailyReport = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, report}: { eventId: IdParam, report: EventDailyReportRequest }) =>
            eventDailyReportClient.update(eventId, report),

        onSuccess: (response, variables) => {
            queryClient.setQueryData([GET_EVENT_DAILY_REPORT_QUERY_KEY, variables.eventId], response.data);
        },
    });
};
