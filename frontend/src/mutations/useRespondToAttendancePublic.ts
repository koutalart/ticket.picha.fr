import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {attendeeClientPublic} from "../api/attendee.client.ts";
import {GET_ATTENDEE_ATTENDANCE_PUBLIC_QUERY_KEY} from "../queries/useGetAttendeeAttendancePublic.ts";

interface AttendanceResponseParams {
    eventId: IdParam;
    attendeeShortId: string;
    code: string;
}

export const useConfirmAttendancePublic = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, attendeeShortId, code}: AttendanceResponseParams) =>
            attendeeClientPublic.confirmAttendance(eventId, attendeeShortId, code),
        onSuccess: () => queryClient.invalidateQueries({queryKey: [GET_ATTENDEE_ATTENDANCE_PUBLIC_QUERY_KEY]}),
    });
};

export const useCancelAttendancePublic = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, attendeeShortId, code}: AttendanceResponseParams) =>
            attendeeClientPublic.cancelAttendance(eventId, attendeeShortId, code),
        onSuccess: () => queryClient.invalidateQueries({queryKey: [GET_ATTENDEE_ATTENDANCE_PUBLIC_QUERY_KEY]}),
    });
};
