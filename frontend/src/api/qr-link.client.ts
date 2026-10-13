import {api} from "./client";
import {publicApi} from "./public-client";
import {
    GenericDataResponse,
    IdParam,
    MetaConversionsApiTestResult,
    QrLink,
    QrLinkDesign,
    QrLinkDestinationType,
    QrLinkPublic,
    QrLinkSocialLink,
    QrLinkStats,
    QrLinkStatus,
} from "../types";

export interface QrLinkRequest {
    name: string;
    destination_type: QrLinkDestinationType;
    destination_url?: string | null;
    event_id?: IdParam | null;
    title?: string | null;
    description?: string | null;
    social_links?: QrLinkSocialLink[];
    design?: QrLinkDesign;
    status?: QrLinkStatus;
}

export type QrLinkVisitType = 'SCAN' | 'CLICK';

export const qrLinkClient = {
    all: async (organizerId: IdParam) => {
        const response = await api.get<GenericDataResponse<QrLink[]>>(`organizers/${organizerId}/qr-links`);
        return response.data;
    },
    create: async (organizerId: IdParam, qrLink: QrLinkRequest) => {
        const response = await api.post<GenericDataResponse<QrLink>>(`organizers/${organizerId}/qr-links`, qrLink);
        return response.data;
    },
    update: async (organizerId: IdParam, qrLinkId: IdParam, qrLink: QrLinkRequest) => {
        const response = await api.put<GenericDataResponse<QrLink>>(`organizers/${organizerId}/qr-links/${qrLinkId}`, qrLink);
        return response.data;
    },
    delete: async (organizerId: IdParam, qrLinkId: IdParam) => {
        await api.delete(`organizers/${organizerId}/qr-links/${qrLinkId}`);
    },
    stats: async (organizerId: IdParam, qrLinkId: IdParam, days: number | null) => {
        const response = await api.get<GenericDataResponse<QrLinkStats>>(
            `organizers/${organizerId}/qr-links/${qrLinkId}/stats`,
            {params: {days: days ?? 'all'}},
        );
        return response.data;
    },
    testMetaConversionsApi: async (organizerId: IdParam) => {
        const response = await api.post<GenericDataResponse<MetaConversionsApiTestResult>>(
            `organizers/${organizerId}/settings/meta-conversions-api/test`
        );
        return response.data;
    },
};

export const qrLinkClientPublic = {
    findByShortId: async (shortId: string) => {
        const response = await publicApi.get<GenericDataResponse<QrLinkPublic>>(`/qr-links/${shortId}`);
        return response.data;
    },
    recordClick: (shortId: string, target: string) => {
        return publicApi.post(`/qr-links/${shortId}/visits`, {type: 'CLICK' satisfies QrLinkVisitType, target});
    },
};
