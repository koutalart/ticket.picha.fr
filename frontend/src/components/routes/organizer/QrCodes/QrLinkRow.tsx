import {useRef, useState} from "react";
import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {ActionIcon, Badge, Button, CopyButton as MantineCopy, Menu, Tooltip} from "@mantine/core";
import {
    IconChartBar,
    IconCheck,
    IconCopy,
    IconDotsVertical,
    IconDownload,
    IconExternalLink,
    IconPencil,
    IconPhoto,
    IconPlayerPause,
    IconPlayerPlay,
    IconTrash,
} from "@tabler/icons-react";
import {QrLink} from "../../../../types.ts";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {formatNumber} from "../../../../utilites/helpers.ts";
import {qrLinkClient} from "../../../../api/qr-link.client.ts";
import {showError} from "../../../../utilites/notifications.tsx";
import {downloadQrPng, downloadQrSvg, qrFileName, QrCodeImage, triggerDownload} from "./QrCodeImage.tsx";
import classes from "./QrCodes.module.scss";

interface QrLinkRowProps {
    qrLink: QrLink;
    currency: string;
    campaignName?: string;
    showRevenue: boolean;
    onEdit: () => void;
    onStats: () => void;
    onToggleStatus: () => void;
    onDelete: () => void;
}

export const QrLinkRow = ({qrLink, currency, campaignName, showRevenue, onEdit, onStats, onToggleStatus, onDelete}: QrLinkRowProps) => {
    const qrRef = useRef<HTMLDivElement>(null);
    const {organizerId} = useParams();
    const [isDownloadingPoster, setIsDownloadingPoster] = useState(false);
    const isPaused = qrLink.status === 'PAUSED';
    const hasPoster = !!qrLink.design?.template && qrLink.design.template !== 'none';

    const destinationLabel = {
        LINK_PAGE: t`Link page`,
        EVENT: t`Event page`,
        URL: t`Direct link`,
        APP: t`App`,
    }[qrLink.destination_type];

    const downloadPoster = () => {
        setIsDownloadingPoster(true);
        qrLinkClient.poster(organizerId!, qrLink.id)
            .then((blob) => {
                const url = URL.createObjectURL(blob);
                triggerDownload(url, qrFileName(`${t`visual`}-${qrLink.name}`, 'png'));
                setTimeout(() => URL.revokeObjectURL(url), 1000);
            })
            .catch(() => showError(t`Unable to generate the visual`))
            .finally(() => setIsDownloadingPoster(false));
    };

    return (
        <div className={classes.row} data-paused={isPaused}>
            <button type="button" className={classes.rowQr} onClick={onEdit} aria-label={t`Edit`}>
                <QrCodeImage ref={qrRef} value={qrLink.public_url} design={qrLink.design} size={44}/>
            </button>

            <div className={classes.rowMain}>
                <button type="button" className={classes.rowName} onClick={onEdit} title={qrLink.name}>
                    {qrLink.name}
                </button>
                <div className={classes.rowMeta}>
                    <Badge size="xs" variant="light" radius="sm">{destinationLabel}</Badge>
                    {isPaused && <Badge size="xs" color="gray" radius="sm">{t`Paused`}</Badge>}
                    {campaignName && <span className={classes.rowMetaText}>{campaignName}</span>}
                    {qrLink.event_title && <span className={classes.rowMetaText}>{qrLink.event_title}</span>}
                </div>
            </div>

            <div className={classes.rowNumbers}>
                <div><strong>{formatNumber(qrLink.scans_count)}</strong><span>{t`Scans`}</span></div>
                <div><strong>{formatNumber(qrLink.unique_scans_count)}</strong><span>{t`Unique`}</span></div>
                <div><strong>{formatNumber(qrLink.orders_count)}</strong><span>{t`Orders`}</span></div>
                {showRevenue && (
                    <div><strong>{formatCurrency(qrLink.revenue_gross, currency)}</strong><span>{t`Revenue`}</span></div>
                )}
            </div>

            <div className={classes.rowActions}>
                {hasPoster ? (
                    <Button size="compact-sm" variant="light" leftSection={<IconPhoto size={14}/>}
                            loading={isDownloadingPoster} onClick={downloadPoster}>
                        {t`Visual`}
                    </Button>
                ) : (
                    <Button size="compact-sm" variant="light" leftSection={<IconDownload size={14}/>}
                            onClick={() => downloadQrPng(qrRef.current, qrLink.name, qrLink.design)}>
                        {t`PNG`}
                    </Button>
                )}
                <Tooltip label={t`Statistics`} withArrow>
                    <ActionIcon variant="subtle" size="lg" onClick={onStats} aria-label={t`Statistics`}>
                        <IconChartBar size={18}/>
                    </ActionIcon>
                </Tooltip>
                <Menu position="bottom-end" withinPortal shadow="md" width={230}>
                    <Menu.Target>
                        <ActionIcon variant="subtle" size="lg" color="gray" aria-label={t`Actions`}>
                            <IconDotsVertical size={18}/>
                        </ActionIcon>
                    </Menu.Target>
                    <Menu.Dropdown>
                        <Menu.Item leftSection={<IconPencil size={14}/>} onClick={onEdit}>{t`Edit`}</Menu.Item>
                        <MantineCopy value={qrLink.public_url}>
                            {({copied, copy}) => (
                                <Menu.Item leftSection={copied ? <IconCheck size={14}/> : <IconCopy size={14}/>}
                                           onClick={copy} closeMenuOnClick={false}>
                                    {copied ? t`Copied` : t`Copy the link`}
                                </Menu.Item>
                            )}
                        </MantineCopy>
                        <Menu.Item leftSection={<IconExternalLink size={14}/>} component="a" href={qrLink.public_url}
                                   target="_blank">
                            {t`Open`}
                        </Menu.Item>
                        <Menu.Divider/>
                        <Menu.Item leftSection={<IconDownload size={14}/>}
                                   onClick={() => downloadQrPng(qrRef.current, qrLink.name, qrLink.design)}>
                            {t`Download PNG (QR only)`}
                        </Menu.Item>
                        <Menu.Item leftSection={<IconDownload size={14}/>}
                                   onClick={() => downloadQrSvg(qrRef.current, qrLink.name)}>
                            {t`Download SVG (print)`}
                        </Menu.Item>
                        <Menu.Divider/>
                        <Menu.Item leftSection={isPaused ? <IconPlayerPlay size={14}/> : <IconPlayerPause size={14}/>}
                                   onClick={onToggleStatus}>
                            {isPaused ? t`Reactivate` : t`Pause`}
                        </Menu.Item>
                        <Menu.Item color="red" leftSection={<IconTrash size={14}/>} onClick={onDelete}>
                            {t`Delete`}
                        </Menu.Item>
                    </Menu.Dropdown>
                </Menu>
            </div>
        </div>
    );
};
