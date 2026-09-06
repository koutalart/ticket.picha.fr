import {User} from "../types.ts";
import {BoxOfficeContextEvent} from "../api/box-office.client.ts";

export const isBoxOfficeOperator = (user?: Pick<User, 'role'> | null): boolean => {
    return user?.role === 'BOX_OFFICE_OPERATOR';
};

export const kioskPathForEvents = (events: BoxOfficeContextEvent[]): string => {
    if (events.length === 0) {
        return '/kiosk/no-event';
    }
    if (events.length === 1) {
        return `/kiosk/event/${events[0].id}/sell`;
    }
    return '/kiosk/select-event';
};
