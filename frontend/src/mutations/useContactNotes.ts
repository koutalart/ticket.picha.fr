import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {contactClient} from "../api/contact.client.ts";
import {GET_CONTACT_NOTES_QUERY_KEY} from "../queries/useGetContactNotes.ts";

export const useCreateContactNote = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, contactId, note}: { organizerId: IdParam, contactId: IdParam, note: string }) =>
            contactClient.createNote(organizerId, contactId, note),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_CONTACT_NOTES_QUERY_KEY, variables.organizerId, variables.contactId],
        }),
    });
}

export const useDeleteContactNote = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, contactId, noteId}: { organizerId: IdParam, contactId: IdParam, noteId: IdParam }) =>
            contactClient.deleteNote(organizerId, contactId, noteId),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_CONTACT_NOTES_QUERY_KEY, variables.organizerId, variables.contactId],
        }),
    });
}
