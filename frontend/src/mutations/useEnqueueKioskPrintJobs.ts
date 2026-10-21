import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {boxOfficeClient} from "../api/box-office.client.ts";
import {ZebraLabelFormat} from "../hooks/useKioskSettings.ts";
import {GET_KIOSK_PRINT_RELAY_STATUS_QUERY_KEY} from "../queries/useGetKioskPrintRelayStatus.ts";

export const useEnqueueKioskPrintJobs = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, attendeePublicIds, labelFormat}: {
            eventId: IdParam,
            attendeePublicIds: string[],
            labelFormat: ZebraLabelFormat,
        }) => boxOfficeClient.enqueuePrintJobs(eventId, attendeePublicIds, labelFormat),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_KIOSK_PRINT_RELAY_STATUS_QUERY_KEY, variables.eventId],
        }),
    });
}
