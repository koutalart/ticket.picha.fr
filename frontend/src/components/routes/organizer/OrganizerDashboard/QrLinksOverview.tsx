import {NavLink} from "react-router";
import {Skeleton} from '@mantine/core';
import {IconChevronRight} from '@tabler/icons-react';
import {t, Trans} from '@lingui/macro';

import {useGetQrLinks} from "../../../../queries/useGetQrLinks.ts";
import {useGetQrCampaigns} from "../../../../queries/useGetQrCampaigns.ts";
import {formatNumber} from "../../../../utilites/helpers.ts";
import {QrLink} from '../../../../types';
import classes from './OrganizerDashboard.module.scss';

interface CampaignTotal {
    key: string;
    name: string;
    links: number;
    scans: number;
    clicks: number;
}

export const QrLinksOverview = ({organizerId}: { organizerId: string }) => {
    const linksQuery = useGetQrLinks(organizerId);
    const campaignsQuery = useGetQrCampaigns(organizerId);
    const links: QrLink[] = linksQuery.data ?? [];

    if (!linksQuery.isLoading && links.length === 0) {
        return null;
    }

    const campaignNames = new Map((campaignsQuery.data ?? []).map((campaign) => [String(campaign.id), campaign.name]));
    const totals = new Map<string, CampaignTotal>();
    let scans = 0;
    let clicks = 0;

    links.forEach((link) => {
        const linkScans = Number(link.scans_count ?? 0);
        const linkClicks = Number(link.clicks_count ?? 0);
        scans += linkScans;
        clicks += linkClicks;

        const key = link.qr_campaign_id ? String(link.qr_campaign_id) : `link-${link.id}`;
        const name = link.qr_campaign_id ? (campaignNames.get(key) ?? link.name) : link.name;
        const current = totals.get(key) ?? {key, name, links: 0, scans: 0, clicks: 0};
        totals.set(key, {...current, links: current.links + 1, scans: current.scans + linkScans, clicks: current.clicks + linkClicks});
    });

    const top = [...totals.values()]
        .sort((a, b) => (b.scans + b.clicks) - (a.scans + a.clicks))
        .slice(0, 3);

    return (
        <section className={classes.section}>
            <div className={classes.sectionHead}>
                <h2 className={classes.sectionTitle}><Trans>Links and QR codes</Trans></h2>
                <NavLink to="qr-codes" className={classes.seeAll}><Trans>See all</Trans></NavLink>
            </div>

            {linksQuery.isLoading ? <Skeleton height={64} radius="md"/> : (
                <>
                    <div className={`${classes.stats} ${classes.qrStats}`}>
                        <div className={classes.stat}>
                            <span className={classes.statLabel}><Trans>Scans</Trans></span>
                            <span className={classes.statValue}>{formatNumber(scans)}</span>
                        </div>
                        <div className={classes.stat}>
                            <span className={classes.statLabel}><Trans>Clicks</Trans></span>
                            <span className={classes.statValue}>{formatNumber(clicks)}</span>
                        </div>
                        <div className={classes.stat}>
                            <span className={classes.statLabel}><Trans>Tracked links</Trans></span>
                            <span className={classes.statValue}>{formatNumber(links.length)}</span>
                        </div>
                    </div>

                    <div className={classes.orders}>
                        {top.map((item) => (
                            <NavLink key={item.key} to="qr-codes" className={classes.orderRow}>
                                <div className={classes.orderWho}>
                                    <span className={classes.orderName}>{item.name}</span>
                                    <span className={classes.orderMeta}>
                                        {item.links > 1 ? t`${item.links} links` : t`1 link`}
                                    </span>
                                </div>
                                <span className={classes.orderAmount}>
                                    {t`${formatNumber(item.scans)} scans · ${formatNumber(item.clicks)} clicks`}
                                </span>
                                <IconChevronRight size={18} className={classes.orderArrow}/>
                            </NavLink>
                        ))}
                    </div>
                </>
            )}
        </section>
    );
};
