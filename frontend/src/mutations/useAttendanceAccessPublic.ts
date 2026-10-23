import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {attendeeClientPublic} from "../api/attendee.client.ts";

export const useRequestAttendanceAccessCode = () => useMutation({
    mutationFn: ({eventId, contact}: { eventId: IdParam, contact: string }) =>
        attendeeClientPublic.requestAttendanceAccess(eventId, contact),
});

export const useVerifyAttendanceAccessCode = () => useMutation({
    mutationFn: async ({eventId, contact, code}: { eventId: IdParam, contact: string, code: string }) =>
        (await attendeeClientPublic.verifyAttendanceAccess(eventId, contact, code)).data,
});
