import {useQuery} from "@tanstack/react-query";
import {boxOfficeClient} from "../api/box-office.client.ts";
import {GenericDataResponse, IdParam, KioskPrintRelayStatus} from "../types.ts";

export const GET_KIOSK_PRINT_RELAY_STATUS_QUERY_KEY = 'getKioskPrintRelayStatus';

export const useGetKioskPrintRelayStatus = (eventId: IdParam, enabled = true) => {
    return useQuery<GenericDataResponse<KioskPrintRelayStatus>>({
        queryKey: [GET_KIOSK_PRINT_RELAY_STATUS_QUERY_KEY, eventId],
        queryFn: () => boxOfficeClient.getPrintRelayStatus(eventId),
        enabled: enabled && !!eventId,
        refetchInterval: 5000,
    });
};
