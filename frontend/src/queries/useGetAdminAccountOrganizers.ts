import {useQuery} from "@tanstack/react-query";
import {adminClient} from "../api/admin.client";
import {IdParam} from "../types";

export const GET_ADMIN_ACCOUNT_ORGANIZERS_QUERY_KEY = ['admin', 'account', 'organizers'];

export const useGetAdminAccountOrganizers = (accountId: IdParam) => {
    return useQuery({
        queryKey: [...GET_ADMIN_ACCOUNT_ORGANIZERS_QUERY_KEY, accountId],
        queryFn: () => adminClient.getAccountOrganizers(accountId),
        enabled: !!accountId,
    });
};
