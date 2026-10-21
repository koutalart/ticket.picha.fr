import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {contactClient, UpdateContactRequest} from "../api/contact.client.ts";
import {GET_CONTACT_QUERY_KEY} from "../queries/useGetContact.ts";
import {GET_CONTACTS_QUERY_KEY} from "../queries/useGetContacts.ts";

export const useUpdateContact = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, contactId, data}: {
            organizerId: IdParam,
            contactId: IdParam,
            data: UpdateContactRequest,
        }) => contactClient.update(organizerId, contactId, data),

        onSuccess: (_, variables) => Promise.all([
            queryClient.invalidateQueries({queryKey: [GET_CONTACT_QUERY_KEY, variables.organizerId, variables.contactId]}),
            queryClient.invalidateQueries({queryKey: [GET_CONTACTS_QUERY_KEY]}),
        ]),
    });
}
