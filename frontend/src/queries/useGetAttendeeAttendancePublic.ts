import {useQuery} from "@tanstack/react-query";
import {AxiosError} from "axios";
import {AttendeeAttendance, IdParam} from "../types.ts";
import {attendeeClientPublic} from "../api/attendee.client.ts";

export const GET_ATTENDEE_ATTENDANCE_PUBLIC_QUERY_KEY = 'getAttendeeAttendancePublic';

export const useGetAttendeeAttendancePublic = (eventId: IdParam, attendeeShortId: string, code: string) => {
    return useQuery<AttendeeAttendance, AxiosError>({
        queryKey: [GET_ATTENDEE_ATTENDANCE_PUBLIC_QUERY_KEY, eventId, attendeeShortId, code],
        queryFn: async () => {
            const {data} = await attendeeClientPublic.getAttendance(eventId, attendeeShortId, code);
            return data;
        },
        enabled: !!eventId && !!attendeeShortId && !!code,
        refetchOnWindowFocus: false,
        retry: (failureCount, error) => {
            const status = error?.response?.status;
            if (status === 403 || status === 404) {
                return false;
            }
            return failureCount < 2;
        },
    });
};
