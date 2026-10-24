import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {OrganizerTeamContactRequest, smsClient} from "../api/sms.client.ts";
import {GET_ORGANIZER_TEAM_CONTACTS_QUERY_KEY} from "../queries/useGetOrganizerTeamContacts.ts";
import {GET_SMS_CONVERSATIONS_QUERY_KEY} from "../queries/useGetSmsConversations.ts";

export const useSaveOrganizerTeamContact = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, contact}: { organizerId: IdParam, contact: OrganizerTeamContactRequest }) =>
            smsClient.saveTeamContact(organizerId, contact),

        onSuccess: (_, variables) => Promise.all([
            queryClient.invalidateQueries({queryKey: [GET_ORGANIZER_TEAM_CONTACTS_QUERY_KEY, variables.organizerId]}),
            queryClient.invalidateQueries({queryKey: [GET_SMS_CONVERSATIONS_QUERY_KEY, variables.organizerId]}),
        ]),
    });
};
