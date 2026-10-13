import {LoaderFunctionArgs} from "react-router";
import {getQueryClient} from "../utilites/ssrQueryClient.ts";
import {getOrganizerPublicQuery} from "../queries/useGetOrganizerPublic.ts";
import {qrLinkClientPublic} from "../api/qr-link.client.ts";
import {Organizer, QrLinkPublic} from "../types.ts";

export interface QrLinkPageLoaderData {
    qrLink: QrLinkPublic | null;
    organizer: Organizer | null;
}

export const qrLinkPageLoader = async ({params}: LoaderFunctionArgs): Promise<QrLinkPageLoaderData> => {
    const shortId = params.shortId?.toLowerCase();

    if (!shortId || !/^[a-z0-9]{4,32}$/.test(shortId)) {
        return {qrLink: null, organizer: null};
    }

    try {
        const qrLink = await getQueryClient().fetchQuery({
            queryKey: ['getQrLinkPublic', shortId],
            queryFn: async () => (await qrLinkClientPublic.findByShortId(shortId)).data,
            staleTime: 0,
            retry: false,
        });

        const organizer = await getQueryClient()
            .fetchQuery(getOrganizerPublicQuery(qrLink.organizer_id))
            .catch(() => null);

        return {qrLink, organizer};
    } catch (error: any) {
        if (error?.response?.status === 404) {
            return {qrLink: null, organizer: null};
        }
        throw error;
    }
};
