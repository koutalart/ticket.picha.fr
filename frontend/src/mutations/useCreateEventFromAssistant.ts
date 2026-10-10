import {useMutation, useQueryClient} from "@tanstack/react-query";
import {CreateEventFromAssistantRequest, eventAssistantClient} from "../api/event-assistant.client.ts";
import {imageClient} from "../api/image.client.ts";
import {GET_EVENTS_QUERY_KEY} from "../queries/useGetEvents.ts";

export const useCreateEventFromAssistant = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({payload, cover}: { payload: CreateEventFromAssistantRequest, cover?: File | null }) => {
            const {data: event} = await eventAssistantClient.create(payload);
            let coverUploaded = true;
            if (cover) {
                try {
                    await imageClient.uploadImage(cover, 'EVENT_COVER', event.id);
                } catch {
                    coverUploaded = false;
                }
            }
            return {event, coverUploaded};
        },
        onSuccess: () => queryClient.invalidateQueries({queryKey: [GET_EVENTS_QUERY_KEY]}),
    });
};
