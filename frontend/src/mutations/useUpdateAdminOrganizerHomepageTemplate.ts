import {useMutation, useQueryClient} from '@tanstack/react-query';
import {adminClient} from '../api/admin.client';
import {IdParam} from '../types';
import {GET_ADMIN_ACCOUNT_ORGANIZERS_QUERY_KEY} from '../queries/useGetAdminAccountOrganizers';

export const useUpdateAdminOrganizerHomepageTemplate = (accountId: IdParam) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({organizerId, homepageTemplate}: { organizerId: IdParam, homepageTemplate: string }) => {
            return await adminClient.updateOrganizerHomepageTemplate(accountId, organizerId, homepageTemplate);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({
                queryKey: [...GET_ADMIN_ACCOUNT_ORGANIZERS_QUERY_KEY, accountId],
            });
        },
    });
};
