import {useMutation} from "@tanstack/react-query";
import {eventAssistantClient, ResearchDescriptionRequest} from "../api/event-assistant.client.ts";

export const useResearchEventDescription = () => {
    return useMutation({
        mutationFn: (payload: ResearchDescriptionRequest) => eventAssistantClient.researchDescription(payload),
    });
};
