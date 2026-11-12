import {useQuery} from "@tanstack/react-query";
import {viewerClient} from "../api/viewer.client.ts";

export const GET_VIEWER_EVENTS_QUERY_KEY = 'getViewerEvents';

export const useGetViewerEvents = () => {
    return useQuery({
        queryKey: [GET_VIEWER_EVENTS_QUERY_KEY],
        queryFn: viewerClient.events,
    });
};
