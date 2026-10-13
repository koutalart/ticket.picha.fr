import {useRef, useState} from "react";
import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {Badge, Button, Group, Text} from "@mantine/core";
import {
    IconChartBar,
    IconDownload,
    IconExternalLink,
    IconPhoto,
    IconPencil,
    IconPlayerPause,
    IconPlayerPlay,
    IconTrash,
} from "@tabler/icons-react";
import {Card} from "../../../common/Card";
import {CopyButton} from "../../../common/CopyButton";
import {ActionMenu} from "../../../common/ActionMenu";
import {QrLink} from "../../../../types.ts";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {downloadQrPng, downloadQrSvg, QrCodeImage, triggerDownload, qrFileName} from "./QrCodeImage.tsx";
import {qrLinkClient} from "../../../../api/qr-link.client.ts";
import {showError} from "../../../../utilites/notifications.tsx";
import classes from "./QrCodes.module.scss";

interface QrLinkCardProps {
    qrLink: QrLink;
    currency: string;
    onEdit: () => void;
    onStats: () => void;
    onToggleStatus: () => void;
    onDelete: () => void;
}

export const QrLinkCard = ({qrLink, currency, onEdit, onStats, onToggleStatus, onDelete}: QrLinkCardProps) => {
    const qrRef = useRef<HTMLDivElement>(null);
    const {organizerId} = useParams();
    const [isDownloadingPoster, setIsDownloadingPoster] = useState(false);
    const isPaused = qrLink.status === 'PAUSED';
    const hasPoster = !!qrLink.design?.template && qrLink.design.template !== 'none';

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

    const destinationLabel = {
        LINK_PAGE: t`Link page`,
        EVENT: t`Event page`,
        URL: t`Direct link`,
    }[qrLink.destination_type];

    const metrics = [
        {label: t`Scans`, value: qrLink.scans_count},
        {label: t`Unique`, value: qrLink.unique_scans_count},
        {label: t`Orders`, value: qrLink.orders_count},
        {label: t`Revenue`, value: formatCurrency(qrLink.revenue_gross, currency)},
    ];

    return (
        <Card className={`${classes.qrCard} ${isPaused ? classes.paused : ''}`}>
            <div className={classes.qrCardTop}>
                <QrCodeImage ref={qrRef} value={qrLink.public_url} design={qrLink.design} size={96}/>
                <div className={classes.qrCardInfo}>
                    <Group justify="space-between" wrap="nowrap" align="flex-start">
                        <div className={classes.qrName}>{qrLink.name}</div>
                        <ActionMenu itemsGroups={[{
                            label: t`Actions`,
                            items: [
                                {label: t`Edit`, icon: <IconPencil size={14}/>, onClick: onEdit},
                                {label: t`Download SVG (print)`, icon: <IconDownload size={14}/>, onClick: () => downloadQrSvg(qrRef.current, qrLink.name)},
                                {
                                    label: isPaused ? t`Reactivate` : t`Pause`,
                                    icon: isPaused ? <IconPlayerPlay size={14}/> : <IconPlayerPause size={14}/>,
                                    onClick: onToggleStatus,
                                },
                                {label: t`Delete`, icon: <IconTrash size={14}/>, onClick: onDelete, color: 'red'},
                            ],
                        }]}/>
                    </Group>
                    <Group gap={6} mt={4}>
                        <Badge size="xs" variant="light">{destinationLabel}</Badge>
                        {isPaused && <Badge size="xs" color="gray">{t`Paused`}</Badge>}
                    </Group>
                    {qrLink.event_title && <Text size="xs" c="dimmed" mt={4} lineClamp={1}>{qrLink.event_title}</Text>}
                    <Group gap={4} mt={6} wrap="nowrap">
                        <Text size="xs" c="dimmed" truncate>{qrLink.public_url.replace(/^https?:\/\//, '')}</Text>
                        <CopyButton value={qrLink.public_url}/>
                    </Group>
                </div>
            </div>

            <div className={classes.metrics}>
                {metrics.map(metric => (
                    <div key={metric.label}>
                        <div className={classes.metricValue}>{metric.value}</div>
                        <div className={classes.metricLabel}>{metric.label}</div>
                    </div>
                ))}
            </div>

            {hasPoster && (
                <Button size="xs" leftSection={<IconPhoto size={14}/>} onClick={downloadPoster}
                        loading={isDownloadingPoster}>
                    {t`Download the visual`}
                </Button>
            )}

            <Group gap="xs">
                <Button size="xs" variant="light" leftSection={<IconDownload size={14}/>}
                        onClick={() => downloadQrPng(qrRef.current, qrLink.name, qrLink.design)}>
                    {t`PNG`}
                </Button>
                <Button size="xs" variant="light" leftSection={<IconChartBar size={14}/>} onClick={onStats}>
                    {t`Statistics`}
                </Button>
                <Button size="xs" variant="subtle" component="a" href={qrLink.public_url} target="_blank"
                        leftSection={<IconExternalLink size={14}/>}>
                    {t`Open`}
                </Button>
            </Group>
        </Card>
    );
};
