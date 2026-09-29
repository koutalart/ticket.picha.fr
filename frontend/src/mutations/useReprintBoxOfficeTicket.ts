import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {boxOfficeClient} from "../api/box-office.client.ts";

export const useReprintBoxOfficeTicket = () => {
    return useMutation({
        mutationFn: ({eventId, attendeePublicId}: {
            eventId: IdParam,
            attendeePublicId: string,
        }) => boxOfficeClient.reprintTicket(eventId, attendeePublicId),
    });
}
