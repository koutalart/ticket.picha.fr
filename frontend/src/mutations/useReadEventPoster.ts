import {useMutation} from "@tanstack/react-query";
import {eventAssistantClient} from "../api/event-assistant.client.ts";

export const useReadEventPoster = () => {
    return useMutation({
        mutationFn: ({poster, timezone}: { poster: File, timezone?: string }) =>
            eventAssistantClient.readPoster(poster, timezone),
    });
};
