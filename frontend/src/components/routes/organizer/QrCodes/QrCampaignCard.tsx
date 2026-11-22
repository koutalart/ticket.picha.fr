import {plural, t} from "@lingui/macro";
import {ActionIcon, Menu} from "@mantine/core";
import {IconDotsVertical, IconPencil, IconTrash} from "@tabler/icons-react";
import {QrLink} from "../../../../types.ts";
import {formatNumber} from "../../../../utilites/helpers.ts";
import {qrTotal, shortQrName} from "./qrLinkHelpers.tsx";
import classes from "./QrCodes.module.scss";

interface QrCampaignCardProps {
    name: string;
    links: QrLink[];
    isActive: boolean;
    isMuted?: boolean;
    onSelect: () => void;
    onRename?: () => void;
    onDelete?: () => void;
}

export const QrCampaignCard = ({name, links, isActive, isMuted, onSelect, onRename, onDelete}: QrCampaignCardProps) => {
    const total = links.reduce((sum, qrLink) => sum + qrTotal(qrLink), 0);
    const orders = links.reduce((sum, qrLink) => sum + qrLink.orders_count, 0);
    const best = [...links]
        .filter(qrLink => qrTotal(qrLink) > 0)
        .sort((a, b) => qrTotal(b) - qrTotal(a))
        .slice(0, 3);

    return (
        <div className={classes.campaignCard} data-active={isActive} data-muted={!!isMuted}>
            <button type="button" className={classes.campaignCardButton} onClick={onSelect} aria-pressed={isActive}>
                <span className={classes.campaignCardName} title={name}>{name}</span>
                <span className={classes.campaignCardTotal}>
                    <strong>{formatNumber(total)}</strong>
                    <span>{t`scans + clicks`}</span>
                </span>
                <span className={classes.campaignCardMeta}>
                    {plural(links.length, {one: '# QR code', other: '# QR codes'})}
                    {orders > 0 && <>, {plural(orders, {one: '# order', other: '# orders'})}</>}
                </span>
                {best.length > 0 ? (
                    <span className={classes.campaignCardBest}>
                        {best.map(qrLink => (
                            <span key={qrLink.id} className={classes.campaignCardBestItem} title={qrLink.name}>
                                <span>{shortQrName(qrLink.name, name)}</span>
                                <strong>{formatNumber(qrTotal(qrLink))}</strong>
                            </span>
                        ))}
                    </span>
                ) : (
                    <span className={classes.campaignCardHint}>
                        {links.length ? t`No scans yet` : t`No QR codes yet`}
                    </span>
                )}
            </button>

            {(onRename || onDelete) && (
                <Menu position="bottom-end" withinPortal shadow="md">
                    <Menu.Target>
                        <ActionIcon className={classes.campaignCardMenu} variant="subtle" color="gray"
                                    aria-label={t`Campaign actions`}>
                            <IconDotsVertical size={16}/>
                        </ActionIcon>
                    </Menu.Target>
                    <Menu.Dropdown>
                        {onRename && (
                            <Menu.Item leftSection={<IconPencil size={14}/>} onClick={onRename}>{t`Rename`}</Menu.Item>
                        )}
                        {onDelete && (
                            <Menu.Item color="red" leftSection={<IconTrash size={14}/>} onClick={onDelete}>
                                {t`Delete the campaign`}
                            </Menu.Item>
                        )}
                    </Menu.Dropdown>
                </Menu>
            )}
        </div>
    );
};
