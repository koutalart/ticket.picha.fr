import {useMutation} from "@tanstack/react-query";
import {eventAssistantClient} from "../api/event-assistant.client.ts";

export const useImproveEventDescription = () => {
    return useMutation({
        mutationFn: (payload: { description: string, title?: string, venue?: string }) =>
            eventAssistantClient.improveDescription(payload),
    });
};
