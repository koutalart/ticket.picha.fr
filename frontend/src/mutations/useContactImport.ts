import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {contactClient, CreateContactRequest, RunContactImportRequest} from "../api/contact.client.ts";
import {GET_CONTACTS_QUERY_KEY} from "../queries/useGetContacts.ts";
import {GET_CONTACT_TAGS_QUERY_KEY} from "../queries/useGetContactTags.ts";

const invalidateContacts = (queryClient: ReturnType<typeof useQueryClient>) => Promise.all([
    queryClient.invalidateQueries({queryKey: [GET_CONTACTS_QUERY_KEY]}),
    queryClient.invalidateQueries({queryKey: [GET_CONTACT_TAGS_QUERY_KEY]}),
]);

export const useCreateContact = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, data}: { organizerId: IdParam, data: CreateContactRequest }) =>
            contactClient.create(organizerId, data),
        onSuccess: () => invalidateContacts(queryClient),
    });
}

export const useUploadContactImport = () => useMutation({
    mutationFn: ({organizerId, file}: { organizerId: IdParam, file: File }) => contactClient.uploadImport(organizerId, file),
});

export const useRunContactImport = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, data}: { organizerId: IdParam, data: RunContactImportRequest }) =>
            contactClient.runImport(organizerId, data),
        onSuccess: (result) => result.dry_run ? undefined : invalidateContacts(queryClient),
    });
}

export const useRevertContactImport = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, importId}: { organizerId: IdParam, importId: IdParam }) =>
            contactClient.revertImport(organizerId, importId),
        onSuccess: () => invalidateContacts(queryClient),
    });
}
