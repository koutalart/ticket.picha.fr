import {useState} from "react";
import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {Alert, Drawer, Progress, SegmentedControl, SimpleGrid, Skeleton, Stack, Text} from "@mantine/core";
import {AreaChart, BarChart} from "@mantine/charts";
import {QrLink, QrLinkBreakdownItem} from "../../../../types.ts";
import {useGetQrLinkStats} from "../../../../queries/useGetQrLinkStats.ts";
import {formatCurrency} from "../../../../utilites/currency.ts";
import classes from "./QrCodes.module.scss";

interface QrLinkStatsDrawerProps {
    qrLink: QrLink | null;
    currency: string;
    onClose: () => void;
}

const PERIODS = ['7', '30', '90', 'all'];

const targetLabel = (key: string | null): string => {
    switch (key) {
        case 'tickets':
            return t`Ticket button`;
        case 'events':
            return t`Events button`;
        case 'website':
            return t`Website`;
        case null:
            return t`Unknown`;
        default:
            return key.charAt(0).toUpperCase() + key.slice(1);
    }
};

const deviceLabel = (key: string | null): string => ({
    mobile: t`Mobile`,
    tablet: t`Tablet`,
    desktop: t`Computer`,
}[key || ''] || (key === 'other' || !key ? t`Other` : key));

const Breakdown = ({title, items, label = (key) => key || t`Unknown`}: {
    title: string,
    items: QrLinkBreakdownItem[],
    label?: (key: string | null) => string,
}) => {
    const total = items.reduce((sum, item) => sum + item.count, 0);

    return (
        <div className={classes.breakdown}>
            <Text size="sm" fw={600} mb="xs">{title}</Text>
            {items.length === 0 && <Text size="xs" c="dimmed">{t`No data yet`}</Text>}
            {items.map(item => (
                <div key={item.key ?? 'none'} className={classes.breakdownRow}>
                    <Text size="xs" className={classes.breakdownLabel}>{label(item.key)}</Text>
                    <Progress value={total ? item.count / total * 100 : 0} size="sm" className={classes.breakdownBar}/>
                    <Text size="xs" c="dimmed" className={classes.breakdownCount}>{item.count}</Text>
                </div>
            ))}
        </div>
    );
};

export const QrLinkStatsDrawer = ({qrLink, currency, onClose}: QrLinkStatsDrawerProps) => {
    const {organizerId} = useParams();
    const [period, setPeriod] = useState('30');
    const days = period === 'all' ? null : Number(period);
    const statsQuery = useGetQrLinkStats(organizerId!, qrLink?.id, days);
    const stats = statsQuery.data;

    const kpis = stats ? [
        {label: t`Scans`, value: stats.scans},
        {label: t`Unique visitors`, value: stats.unique_scans},
        {label: t`Clicks on the page`, value: stats.clicks},
        {label: t`Orders`, value: stats.orders},
        {label: t`Revenue`, value: formatCurrency(stats.revenue_gross, currency)},
        {label: t`Conversion`, value: stats.conversion_rate === null ? '—' : `${stats.conversion_rate} %`},
    ] : [];

    return (
        <Drawer
            opened={!!qrLink}
            onClose={onClose}
            position="right"
            size="xl"
            title={<Text fw={700}>{qrLink?.name}</Text>}
        >
            <Stack gap="lg">
                <SegmentedControl
                    value={period}
                    onChange={setPeriod}
                    data={PERIODS.map(value => ({
                        value,
                        label: value === 'all' ? t`Since creation` : t`${value} days`,
                    }))}
                />

                {!stats && <Skeleton height={300}/>}

                {stats && (
                    <>
                        <SimpleGrid cols={{base: 2, sm: 3}} spacing="sm">
                            {kpis.map(kpi => (
                                <div key={kpi.label} className={classes.kpi}>
                                    <div className={classes.kpiValue}>{kpi.value}</div>
                                    <div className={classes.kpiLabel}>{kpi.label}</div>
                                </div>
                            ))}
                        </SimpleGrid>

                        {stats.scans === 0 && (
                            <Alert variant="light" color="gray">
                                {t`No scans on this period yet. Print or share the QR code: scans appear here in real time.`}
                            </Alert>
                        )}

                        <div>
                            <Text size="sm" fw={600} mb="xs">{t`Scans per day`}</Text>
                            <AreaChart
                                h={200}
                                data={stats.daily.map(day => ({
                                    ...day,
                                    date: new Date(`${day.date}T00:00:00`).toLocaleDateString(undefined, {day: 'numeric', month: 'short'}),
                                }))}
                                dataKey="date"
                                curveType="monotone"
                                tickLine="none"
                                gridAxis="x"
                                withDots={false}
                                series={[
                                    {name: 'scans', label: t`Scans`, color: 'violet.6'},
                                    {name: 'unique_scans', label: t`Unique visitors`, color: 'teal.5'},
                                ]}
                            />
                        </div>

                        <div>
                            <Text size="sm" fw={600} mb="xs">{t`Time of scan`}</Text>
                            <BarChart
                                h={160}
                                data={Array.from({length: 24}, (_, hour) => ({
                                    hour: `${hour}h`,
                                    count: stats.hours.find(item => item.hour === hour)?.count || 0,
                                }))}
                                dataKey="hour"
                                tickLine="none"
                                gridAxis="none"
                                series={[{name: 'count', label: t`Scans`, color: 'violet.5'}]}
                            />
                        </div>

                        <SimpleGrid cols={{base: 1, sm: 2}} spacing="lg">
                            <Breakdown title={t`Clicks on the page`} items={stats.clicks_by_target} label={targetLabel}/>
                            <Breakdown title={t`Devices`} items={stats.devices} label={deviceLabel}/>
                            <Breakdown title={t`Operating systems`} items={stats.operating_systems}/>
                            <Breakdown title={t`Opened from`} items={stats.browsers}/>
                            {stats.countries.length > 0 && <Breakdown title={t`Countries`} items={stats.countries}/>}
                            {stats.referrers.length > 0 && <Breakdown title={t`Referring sites`} items={stats.referrers}/>}
                        </SimpleGrid>

                        <Text size="xs" c="dimmed">
                            {t`Link previews (WhatsApp, Facebook...) and robots are not counted. A unique visitor is a phone that scanned this QR code for the first time.`}
                        </Text>
                    </>
                )}
            </Stack>
        </Drawer>
    );
};
