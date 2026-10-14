import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {qrLinkClient} from "../api/qr-link.client.ts";
import {GET_QR_CAMPAIGNS_QUERY_KEY} from "../queries/useGetQrCampaigns.ts";

export const useUpsertQrCampaign = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, campaignId, name}: { organizerId: IdParam, campaignId?: IdParam, name: string }) =>
            campaignId
                ? qrLinkClient.renameCampaign(organizerId, campaignId, name)
                : qrLinkClient.createCampaign(organizerId, name),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_QR_CAMPAIGNS_QUERY_KEY, variables.organizerId],
        }),
    });
};
