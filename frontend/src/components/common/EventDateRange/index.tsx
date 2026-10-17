import { Event } from "../../../types.ts";
import { formatEventDateRange } from "../../../utilites/dates.ts";

interface EventDateRangeProps {
    event: Event;
}

export const EventDateRange = ({ event }: EventDateRangeProps) => (
    <span>{formatEventDateRange(event.start_date, event.end_date, event.timezone)}</span>
);
