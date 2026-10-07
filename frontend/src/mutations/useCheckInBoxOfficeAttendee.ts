import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {boxOfficeClient} from "../api/box-office.client.ts";
import {GET_BOX_OFFICE_ORDERS_QUERY_KEY} from "../queries/useGetBoxOfficeOrders.ts";
import {GET_BOX_OFFICE_ORDER_QUERY_KEY} from "../queries/useGetBoxOfficeOrder.ts";

export const useCheckInBoxOfficeAttendee = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, attendeePublicId}: {
            eventId: IdParam,
            attendeePublicId: string,
        }) => boxOfficeClient.checkInAttendee(eventId, attendeePublicId),
        onSuccess: () => Promise.all([
            queryClient.invalidateQueries({queryKey: [GET_BOX_OFFICE_ORDERS_QUERY_KEY]}),
            queryClient.invalidateQueries({queryKey: [GET_BOX_OFFICE_ORDER_QUERY_KEY]}),
        ]),
    });
}
