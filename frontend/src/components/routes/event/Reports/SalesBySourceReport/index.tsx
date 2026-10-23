import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {Badge} from "@mantine/core";
import {useGetEvent} from "../../../../../queries/useGetEvent.ts";
import {formatCurrency} from "../../../../../utilites/currency.ts";
import {isFreeEvent} from "../../../../../utilites/eventPricing.ts";
import ReportTable from "../../../../common/ReportTable";

type SourceChannel = 'qr' | 'sms' | 'campaign' | 'referral' | 'direct';

interface SalesBySourceRow {
    channel: SourceChannel;
    source: string | null;
    utm_medium: string | null;
    utm_campaign: string | null;
    orders_count: number;
    tickets_count: number;
    revenue: string;
    visits_count: number | null;
    conversion_rate: string | null;
}

const channelLabel = (channel: SourceChannel): string => {
    switch (channel) {
        case 'qr':
            return t`QR code`;
        case 'sms':
            return t`SMS campaign`;
        case 'campaign':
            return t`Campaign link`;
        case 'referral':
            return t`Referring site`;
        default:
            return t`Direct`;
    }
};

const CHANNEL_COLORS: Record<SourceChannel, string> = {
    qr: 'violet',
    sms: 'teal',
    campaign: 'blue',
    referral: 'orange',
    direct: 'gray',
};

const SalesBySourceReport = () => {
    const {eventId} = useParams();
    const eventQuery = useGetEvent(eventId);
    const event = eventQuery.data;

    if (!event) {
        return null;
    }

    const isFree = isFreeEvent(event);

    const columns = [
        {
            key: 'channel' as const,
            label: t`Channel`,
            sortable: true,
            render: (value: SourceChannel) => (
                <Badge variant="light" color={CHANNEL_COLORS[value] ?? 'gray'} tt="none">{channelLabel(value)}</Badge>
            ),
        },
        {
            key: 'source' as const,
            label: t`Source`,
            sortable: true,
            render: (value: string | null, row: SalesBySourceRow) => value || (row.channel === 'direct' ? t`Direct or unknown` : '-'),
        },
        {
            key: 'utm_medium' as const,
            label: t`Medium`,
            sortable: true,
            render: (value: string | null) => value || '-',
        },
        {
            key: 'utm_campaign' as const,
            label: t`Campaign`,
            sortable: true,
            render: (value: string | null) => value || '-',
        },
        {
            key: 'visits_count' as const,
            label: t`Visits`,
            sortable: true,
            render: (value: number | null) => value ?? '-',
        },
        {
            key: 'orders_count' as const,
            label: isFree ? t`Registrations` : t`Orders`,
            sortable: true,
        },
        {
            key: 'tickets_count' as const,
            label: t`Tickets`,
            sortable: true,
        },
        ...(isFree ? [] : [{
            key: 'revenue' as const,
            label: t`Revenue`,
            sortable: true,
            render: (value: string) => formatCurrency(value, event.currency),
        }]),
        {
            key: 'conversion_rate' as const,
            label: t`Conversion`,
            sortable: true,
            render: (value: string | null) => value === null ? '-' : `${value} %`,
        },
    ];

    return (
        <ReportTable<SalesBySourceRow>
            title={isFree ? t`Registrations by source` : t`Sales by source`}
            columns={columns}
            isLoading={eventQuery.isLoading}
            downloadFileName={isFree ? 'inscriptions_par_source.csv' : 'ventes_par_source.csv'}
            event={event}
        />
    );
};

export default SalesBySourceReport;
