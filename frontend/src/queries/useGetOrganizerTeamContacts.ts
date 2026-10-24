import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";

export const GET_ORGANIZER_TEAM_CONTACTS_QUERY_KEY = 'getOrganizerTeamContacts';

export const useGetOrganizerTeamContacts = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_ORGANIZER_TEAM_CONTACTS_QUERY_KEY, organizerId],
        queryFn: async () => (await smsClient.teamContacts(organizerId)).data,
        enabled: !!organizerId,
    });
};
