import {useState} from "react";
import {t, Trans} from "@lingui/macro";
import {Alert, Button, Skeleton, Text} from "@mantine/core";
import {IconUsers} from "@tabler/icons-react";
import {IdParam, SmsCampaign} from "../../../../types.ts";
import {useGetSmsCampaigns} from "../../../../queries/useGetSmsCampaigns.ts";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {formatNumber} from "../../../../utilites/helpers.ts";
import {CampaignRecipientsDrawer} from "./CampaignRecipientsDrawer.tsx";
import classes from "./Sms.module.scss";

interface CampaignListProps {
    organizerId: IdParam;
    defaultCurrency: string;
}

const percent = (value: number, total: number) => total > 0 ? `${Math.round((value / total) * 100)} %` : '0 %';

export const CampaignList = ({organizerId, defaultCurrency}: CampaignListProps) => {
    const campaignsQuery = useGetSmsCampaigns(organizerId);
    const [selected, setSelected] = useState<SmsCampaign | null>(null);
    const campaigns = campaignsQuery.data ?? [];

    if (campaignsQuery.isLoading) {
        return <Skeleton height={220} radius="md"/>;
    }

    if (campaigns.length === 0) {
        return (
            <Alert variant="light" color="gray">
                {t`No SMS campaign yet. The statistics of your SMS campaigns (delivery, clicks and ticket sales) will appear here.`}
            </Alert>
        );
    }

    return (
        <div className={classes.campaigns}>
            {campaigns.map(campaign => {
                const currency = campaign.currency || defaultCurrency;
                const kpis = [
                    {label: t`Recipients`, value: formatNumber(campaign.recipients_count)},
                    {
                        label: t`Delivered`,
                        value: formatNumber(campaign.delivered_count),
                        hint: percent(campaign.delivered_count, campaign.recipients_count),
                    },
                    {label: t`Failed`, value: formatNumber(campaign.failed_count)},
                    {
                        label: t`People who clicked`,
                        value: formatNumber(campaign.clickers_count),
                        hint: percent(campaign.clickers_count, campaign.recipients_count),
                    },
                    {label: t`Visits`, value: formatNumber(campaign.visits_count)},
                    {label: t`Orders`, value: formatNumber(campaign.orders_count)},
                    {label: t`Revenue`, value: formatCurrency(campaign.revenue_gross, currency)},
                ];

                return (
                    <section key={campaign.id} className={classes.campaign}>
                        <div className={classes.campaignHeader}>
                            <div>
                                <h2 className={classes.campaignName}>{campaign.name}</h2>
                                <Text size="sm" c="dimmed">
                                    {campaign.event_title
                                        ? <Trans>For {campaign.event_title}</Trans>
                                        : t`No event attached`}
                                    {', '}
                                    {new Date(campaign.created_at).toLocaleDateString(undefined, {day: 'numeric', month: 'long', year: 'numeric'})}
                                </Text>
                            </div>
                            <Button variant="light" leftSection={<IconUsers size={16}/>} onClick={() => setSelected(campaign)}>
                                {t`See the recipients`}
                            </Button>
                        </div>

                        <div className={classes.campaignKpis}>
                            {kpis.map(kpi => (
                                <div key={kpi.label} className={classes.kpi}>
                                    <div className={classes.kpiValue}>{kpi.value}</div>
                                    <div className={classes.kpiLabel}>
                                        {kpi.label}{kpi.hint ? ` (${kpi.hint})` : ''}
                                    </div>
                                </div>
                            ))}
                        </div>

                        {campaign.pending_count > 0 && (
                            <Text size="xs" c="dimmed" mt="xs">
                                {t`${formatNumber(campaign.pending_count)} SMS still waiting for a delivery report.`}
                            </Text>
                        )}
                    </section>
                );
            })}

            <CampaignRecipientsDrawer
                organizerId={organizerId}
                campaign={selected}
                currency={selected?.currency || defaultCurrency}
                onClose={() => setSelected(null)}
            />
        </div>
    );
};
