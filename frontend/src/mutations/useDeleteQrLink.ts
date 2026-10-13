import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {qrLinkClient} from "../api/qr-link.client.ts";
import {GET_QR_LINKS_QUERY_KEY} from "../queries/useGetQrLinks.ts";

export const useDeleteQrLink = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, qrLinkId}: { organizerId: IdParam, qrLinkId: IdParam }) =>
            qrLinkClient.delete(organizerId, qrLinkId),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_QR_LINKS_QUERY_KEY, variables.organizerId],
        }),
    });
};
