import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {boxOfficeClient} from "../api/box-office.client.ts";
import {GET_KIOSK_PRINT_RELAY_STATUS_QUERY_KEY} from "../queries/useGetKioskPrintRelayStatus.ts";

export const useGenerateKioskPrintRelayToken = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId}: { eventId: IdParam }) => boxOfficeClient.generatePrintRelayToken(eventId),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_KIOSK_PRINT_RELAY_STATUS_QUERY_KEY, variables.eventId],
        }),
    });
}
