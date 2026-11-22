import {useRef} from "react";
import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {ActionIcon, Badge, CopyButton as MantineCopy, Menu} from "@mantine/core";
import {
    IconChartBar,
    IconCheck,
    IconChevronRight,
    IconCopy,
    IconDotsVertical,
    IconDownload,
    IconExternalLink,
    IconPencil,
    IconPhoto,
    IconPlayerPause,
    IconPlayerPlay,
    IconTicket,
    IconTrash,
} from "@tabler/icons-react";
import {QrLink} from "../../../../types.ts";
import {formatNumber} from "../../../../utilites/helpers.ts";
import {downloadQrPng, downloadQrSvg, QrCodeImage} from "./QrCodeImage.tsx";
import {destinationLabel, hasPosterDesign, qrTotal, shortQrName, usePosterDownload} from "./qrLinkHelpers.tsx";
import classes from "./QrCodes.module.scss";

interface QrLinkRowProps {
    qrLink: QrLink;
    campaignName?: string | null;
    onOpen: () => void;
    onEdit: () => void;
    onToggleStatus: () => void;
    onDelete: () => void;
}

export const QrLinkRow = ({qrLink, campaignName, onOpen, onEdit, onToggleStatus, onDelete}: QrLinkRowProps) => {
    const qrRef = useRef<HTMLDivElement>(null);
    const {organizerId} = useParams();
    const poster = usePosterDownload(organizerId!);
    const isPaused = qrLink.status === 'PAUSED';
    const total = qrTotal(qrLink);
    const label = shortQrName(qrLink.name, campaignName);

    return (
        <div className={classes.row} data-paused={isPaused}>
            <button type="button" className={classes.rowButton} onClick={onOpen} title={qrLink.name}>
                <span className={classes.rowQr}>
                    <QrCodeImage ref={qrRef} value={qrLink.public_url} design={qrLink.design} size={40}/>
                </span>
                <span className={classes.rowMain}>
                    <span className={classes.rowName}>{label}</span>
                    <span className={classes.rowMeta}>
                        <span>{destinationLabel(qrLink.destination_type)}</span>
                        {qrLink.event_title && <span className={classes.rowMetaText}>{qrLink.event_title}</span>}
                        {isPaused && <Badge size="xs" color="gray" radius="sm">{t`Paused`}</Badge>}
                        {qrLink.orders_count > 0 && (
                            <Badge size="xs" color="green" variant="light" radius="sm"
                                   leftSection={<IconTicket size={10}/>}>
                                {formatNumber(qrLink.orders_count)}
                            </Badge>
                        )}
                    </span>
                </span>
                <span className={classes.rowTotal} data-empty={total === 0}>
                    <strong>{formatNumber(total)}</strong>
                    <span>{t`scans + clicks`}</span>
                </span>
                <IconChevronRight size={16} className={classes.rowChevron}/>
            </button>

            <Menu position="bottom-end" withinPortal shadow="md" width={240}>
                <Menu.Target>
                    <ActionIcon variant="subtle" size="lg" color="gray" aria-label={t`Actions`}>
                        <IconDotsVertical size={18}/>
                    </ActionIcon>
                </Menu.Target>
                <Menu.Dropdown>
                    <Menu.Item leftSection={<IconChartBar size={14}/>} onClick={onOpen}>{t`Statistics`}</Menu.Item>
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
                    {hasPosterDesign(qrLink) && (
                        <Menu.Item leftSection={<IconPhoto size={14}/>} disabled={poster.isDownloading}
                                   onClick={() => poster.download(qrLink)}>
                            {t`Download the visual`}
                        </Menu.Item>
                    )}
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
    );
};
