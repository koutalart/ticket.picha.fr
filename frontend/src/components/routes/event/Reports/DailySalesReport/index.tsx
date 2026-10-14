import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {useGetEvent} from "../../../../../queries/useGetEvent.ts";
import {formatCurrency} from "../../../../../utilites/currency.ts";
import {formatDateWithLocale} from "../../../../../utilites/dates.ts";
import {isFreeEvent} from "../../../../../utilites/eventPricing.ts";
import ReportTable from "../../../../common/ReportTable";

export const DailySalesReport = () => {
    const {eventId} = useParams();
    const eventQuery = useGetEvent(eventId);
    const event = eventQuery.data;

    if (!event) {
        return null;
    }

    const isFree = isFreeEvent(event);
    const money = <K extends string>(key: K, label: string) => ({
        key,
        label,
        sortable: true,
        render: (value: string) => formatCurrency(value, event?.currency),
    });

    const columns = [
        {
            key: 'date' as const,
            label: t`Date`,
            sortable: true,
            render: (value: string) => formatDateWithLocale(value, 'shortDate', event?.timezone)
        },
        ...(isFree ? [] : [
            money('sales_total_gross', t`Gross sales`),
            money('total_tax', t`Tax`),
            money('sales_total_before_additions', t`Net sales`),
        ]),
        {
            key: 'products_sold' as const,
            label: isFree ? t`Registered people` : t`Tickets sold`,
            sortable: true
        },
        {
            key: 'orders_created' as const,
            label: isFree ? t`Confirmed registrations` : t`Completed orders`,
            sortable: true
        },
        ...(isFree ? [] : [
            money('total_fee', t`Fees`),
            money('total_refunded', t`Refunded`),
        ]),
        {
            key: 'total_views' as const,
            label: t`Page views`,
            sortable: true
        }
    ];

    return (
        <ReportTable
            title={isFree ? t`Daily registrations` : t`Daily Sales Report`}
            columns={columns}
            isLoading={eventQuery.isLoading}
            downloadFileName={isFree ? 'inscriptions_par_jour.csv' : 'daily_sales_report.csv'}
            showDateFilter={true}
            event={event}
        />
    );
};
