import {useMutation, useQueryClient} from '@tanstack/react-query';
import {adminClient} from '../api/admin.client';
import {IdParam, OrganizerSiteContent} from '../types';
import {GET_ADMIN_ACCOUNT_ORGANIZERS_QUERY_KEY} from '../queries/useGetAdminAccountOrganizers';

export const useUpdateAdminOrganizerSiteContent = (accountId: IdParam) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({organizerId, siteContent}: { organizerId: IdParam, siteContent: OrganizerSiteContent }) => {
            return await adminClient.updateOrganizerSiteContent(accountId, organizerId, siteContent);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({
                queryKey: [...GET_ADMIN_ACCOUNT_ORGANIZERS_QUERY_KEY, accountId],
            });
        },
    });
};
