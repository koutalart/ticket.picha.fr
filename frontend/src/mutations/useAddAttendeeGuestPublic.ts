import {useMutation, useQueryClient} from "@tanstack/react-query";
import {AddAttendeeGuestRequest, IdParam} from "../types.ts";
import {attendeeClientPublic} from "../api/attendee.client.ts";
import {GET_ATTENDEE_ATTENDANCE_PUBLIC_QUERY_KEY} from "../queries/useGetAttendeeAttendancePublic.ts";

export const useAddAttendeeGuestPublic = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, attendeeShortId, code, guest}: {
            eventId: IdParam,
            attendeeShortId: string,
            code: string,
            guest: AddAttendeeGuestRequest,
        }) => attendeeClientPublic.addGuest(eventId, attendeeShortId, code, guest),

        onSuccess: () => queryClient.invalidateQueries({queryKey: [GET_ATTENDEE_ATTENDANCE_PUBLIC_QUERY_KEY]}),
    });
};
