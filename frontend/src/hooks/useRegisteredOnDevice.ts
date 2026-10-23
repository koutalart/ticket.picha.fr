import {useCallback, useEffect, useState} from "react";
import {Event} from "../types.ts";
import {forgetRegisteredOnDevice, isRegisteredOnDevice} from "../utilites/rememberedVenue.ts";

export const useRegisteredOnDevice = (event?: Event) => {
    const attendanceEnabled = !!event?.id && !!event?.settings?.allow_attendee_attendance_response;
    const [registered, setRegistered] = useState(false);

    useEffect(() => {
        setRegistered(attendanceEnabled && !!event?.id && isRegisteredOnDevice(event.id));
    }, [attendanceEnabled, event?.id]);

    const forget = useCallback(() => {
        if (event?.id) {
            forgetRegisteredOnDevice(event.id);
        }
        setRegistered(false);
    }, [event?.id]);

    return {attendanceEnabled, registered, forget};
};
