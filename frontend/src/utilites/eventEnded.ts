import {Event, EventLifecycleStatus} from "../types.ts";

/**
 * Computed by the API when the page is requested (end date passed, or start date passed by more than 12 hours
 * without an end date), never from the browser clock: the server rendered page and the hydrated one agree.
 */
export const isEventEnded = (event?: Pick<Event, 'lifecycle_status'> | null): boolean =>
    event?.lifecycle_status === EventLifecycleStatus.ENDED;
