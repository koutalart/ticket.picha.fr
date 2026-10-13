import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {qrLinkClient, QrLinkRequest} from "../api/qr-link.client.ts";
import {GET_QR_LINKS_QUERY_KEY} from "../queries/useGetQrLinks.ts";

export const useUpdateQrLink = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, qrLinkId, qrLink}: { organizerId: IdParam, qrLinkId: IdParam, qrLink: QrLinkRequest }) =>
            qrLinkClient.update(organizerId, qrLinkId, qrLink),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_QR_LINKS_QUERY_KEY, variables.organizerId],
        }),
    });
};
