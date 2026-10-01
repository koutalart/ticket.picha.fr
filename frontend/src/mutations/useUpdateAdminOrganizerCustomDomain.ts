import {useMutation, useQueryClient} from '@tanstack/react-query';
import {adminClient} from '../api/admin.client';
import {IdParam} from '../types';
import {GET_ADMIN_ACCOUNT_ORGANIZERS_QUERY_KEY} from '../queries/useGetAdminAccountOrganizers';

export const useUpdateAdminOrganizerCustomDomain = (accountId: IdParam) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({organizerId, customDomain}: { organizerId: IdParam, customDomain: string | null }) => {
            return await adminClient.updateOrganizerCustomDomain(accountId, organizerId, customDomain);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({
                queryKey: [...GET_ADMIN_ACCOUNT_ORGANIZERS_QUERY_KEY, accountId],
            });
        },
    });
};
