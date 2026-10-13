import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {qrLinkClient} from "../api/qr-link.client.ts";

export const useTestMetaConversionsApi = () => {
    return useMutation({
        mutationFn: ({organizerId}: { organizerId: IdParam }) => qrLinkClient.testMetaConversionsApi(organizerId),
    });
};
