import {useQuery} from "@tanstack/react-query";
import {boxOfficeClient, BoxOfficeAttendeeSearchResult} from "../api/box-office.client.ts";
import {GenericDataResponse, IdParam} from "../types.ts";

export const SEARCH_BOX_OFFICE_ATTENDEES_QUERY_KEY = 'searchBoxOfficeAttendees';

export const useSearchBoxOfficeAttendees = (eventId: IdParam | undefined, query: string) => {
    return useQuery<GenericDataResponse<BoxOfficeAttendeeSearchResult[]>>({
        queryKey: [SEARCH_BOX_OFFICE_ATTENDEES_QUERY_KEY, eventId, query],
        queryFn: () => boxOfficeClient.searchAttendees(eventId as IdParam, query),
        enabled: !!eventId && query.trim().length >= 2,
        placeholderData: (previous) => previous,
    });
};
