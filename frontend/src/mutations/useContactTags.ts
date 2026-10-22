import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {contactClient} from "../api/contact.client.ts";
import {GET_CONTACT_TAGS_QUERY_KEY} from "../queries/useGetContactTags.ts";
import {GET_CONTACTS_QUERY_KEY} from "../queries/useGetContacts.ts";
import {GET_CONTACT_QUERY_KEY} from "../queries/useGetContact.ts";

const invalidateTagQueries = (queryClient: ReturnType<typeof useQueryClient>) => Promise.all([
    queryClient.invalidateQueries({queryKey: [GET_CONTACT_TAGS_QUERY_KEY]}),
    queryClient.invalidateQueries({queryKey: [GET_CONTACTS_QUERY_KEY]}),
    queryClient.invalidateQueries({queryKey: [GET_CONTACT_QUERY_KEY]}),
]);

export const useAssignContactTag = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, name, contactIds}: { organizerId: IdParam, name: string, contactIds: IdParam[] }) =>
            contactClient.assignTag(organizerId, name, contactIds),
        onSuccess: () => invalidateTagQueries(queryClient),
    });
}

export const useRemoveContactTag = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, contactId, tagId}: { organizerId: IdParam, contactId: IdParam, tagId: IdParam }) =>
            contactClient.removeTag(organizerId, contactId, tagId),
        onSuccess: () => invalidateTagQueries(queryClient),
    });
}
