import {useState} from "react";
import {t} from "@lingui/macro";
import {IdParam, QrLink, QrLinkDestinationType} from "../../../../types.ts";
import {qrLinkClient} from "../../../../api/qr-link.client.ts";
import {showError} from "../../../../utilites/notifications.tsx";
import {qrFileName, triggerDownload} from "./QrCodeImage.tsx";

const SEPARATOR_PREFIX = /^[\s:|/\-–—]+/;
const COLON_PATTERN = /^.+?\s*[:|]\s+(.+)$/;
const DASH_PATTERN = /^.+?\s+[-–—]\s+(.+)$/;

export const shortQrName = (name: string, campaignName?: string | null): string => {
    const full = name.trim();
    const campaign = campaignName?.trim();

    if (campaign && full.toLowerCase().startsWith(campaign.toLowerCase())
        && SEPARATOR_PREFIX.test(full.slice(campaign.length))) {
        const rest = full.slice(campaign.length).replace(SEPARATOR_PREFIX, '').trim();
        if (rest) {
            return rest;
        }
    }

    const match = full.match(COLON_PATTERN) || full.match(DASH_PATTERN);

    return match?.[1]?.trim() || full;
};

export const qrTotal = (qrLink: QrLink) => qrLink.scans_count + qrLink.clicks_count;

export const hasPosterDesign = (qrLink: QrLink) => !!qrLink.design?.template && qrLink.design.template !== 'none';

export const destinationLabel = (type: QrLinkDestinationType) => ({
    LINK_PAGE: t`Link page`,
    EVENT: t`Event page`,
    URL: t`Direct link`,
    APP: t`App`,
}[type]);

export const usePosterDownload = (organizerId: IdParam) => {
    const [isDownloading, setIsDownloading] = useState(false);

    const download = (qrLink: QrLink) => {
        setIsDownloading(true);
        qrLinkClient.poster(organizerId, qrLink.id)
            .then((blob) => {
                const url = URL.createObjectURL(blob);
                triggerDownload(url, qrFileName(`${t`visual`}-${qrLink.name}`, 'png'));
                setTimeout(() => URL.revokeObjectURL(url), 1000);
            })
            .catch(() => showError(t`Unable to generate the visual`))
            .finally(() => setIsDownloading(false));
    };

    return {download, isDownloading};
};
