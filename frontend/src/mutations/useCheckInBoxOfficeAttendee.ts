import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {boxOfficeClient} from "../api/box-office.client.ts";
import {SEARCH_BOX_OFFICE_ATTENDEES_QUERY_KEY} from "../queries/useSearchBoxOfficeAttendees.ts";

export const useCheckInBoxOfficeAttendee = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, attendeePublicId}: {
            eventId: IdParam,
            attendeePublicId: string,
        }) => boxOfficeClient.checkInAttendee(eventId, attendeePublicId),
        onSuccess: () => queryClient.invalidateQueries({queryKey: [SEARCH_BOX_OFFICE_ATTENDEES_QUERY_KEY]}),
    });
}
