import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {useGetEvent} from "../../../../../queries/useGetEvent.ts";
import {formatCurrency} from "../../../../../utilites/currency.ts";
import {isFreeEvent} from "../../../../../utilites/eventPricing.ts";
import ReportTable from "../../../../common/ReportTable";

const ProductSalesReport = () => {
    const {eventId} = useParams();
    const eventQuery = useGetEvent(eventId);
    const event = eventQuery.data;

    if (!event) {
        return null;
    }

    const isFree = isFreeEvent(event);

    const columns = [
        {
            key: 'product_title' as const,
            label: isFree ? t`Ticket` : t`Product`,
            sortable: true
        },
        {
            key: 'number_sold' as const,
            label: isFree ? t`Registered people` : t`Units sold`,
            sortable: true
        },
        ...(isFree ? [] : [
            {
                key: 'total_gross' as const,
                label: t`Gross sales`,
                sortable: true,
                render: (value: string) => formatCurrency(value, event?.currency)
            },
            {
                key: 'total_tax' as const,
                label: t`Tax`,
                sortable: true,
                render: (value: string) => formatCurrency(value, event?.currency)
            },
            {
                key: 'total_service_fees' as const,
                label: t`Service fees`,
                sortable: true,
                render: (value: string) => formatCurrency(value, event?.currency)
            },
        ]),
    ];

    return (
        <ReportTable
            title={isFree ? t`Registrations per ticket` : t`Product Sales`}
            columns={columns}
            isLoading={eventQuery.isLoading}
            downloadFileName={isFree ? 'inscriptions_par_billet.csv' : 'product_sales_report.csv'}
            event={event}
        />
    );
};

export default ProductSalesReport;
