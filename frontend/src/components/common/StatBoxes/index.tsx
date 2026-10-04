import classes from "./StatBoxes.module.scss";
import {IconCash, IconCreditCardRefund, IconEye, IconReceipt, IconShoppingCart, IconUsers, IconUserCheck, IconTicket} from "@tabler/icons-react";
import {Card} from "../Card";
import {useGetEventStats} from "../../../queries/useGetEventStats.ts";
import {useGetEventCheckInStats} from "../../../queries/useGetEventCheckInStats.ts";
import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {formatNumber} from "../../../utilites/helpers.ts";
import {ReactNode} from "react";

interface StatBoxProps {
    number: string | number;
    description: string;
    icon: ReactNode;
    backgroundColor: string;
}

export const StatBox = ({number, description, icon, backgroundColor}: StatBoxProps) => {
    return (
        <Card className={classes.statistic}>
            <div className={classes.leftPanel}>
                <div className={classes.number}>{number}</div>
                <div className={classes.description}>{description}</div>
            </div>
            <div className={classes.rightPanel}>
                <div className={classes.icon} style={{backgroundColor}}>{icon}</div>
            </div>
        </Card>
    );
};

const isFreeEvent = (event: any): boolean => {
    const products = event?.products ?? [];
    return products.length > 0 && products.every((product: any) => {
        if (product.prices?.length) {
            return product.prices.every((price: any) => Number(price.price ?? 0) <= 0);
        }
        return Number(product.price ?? 0) <= 0;
    });
};

export const StatBoxes = () => {
    const {eventId} = useParams();
    const eventStatsQuery = useGetEventStats(eventId);
    const checkInStatsQuery = useGetEventCheckInStats(eventId);
    const eventQuery = useGetEvent(eventId);
    const event = eventQuery?.data;
    const {data: eventStats} = eventStatsQuery;
    const {data: checkInStats} = checkInStatsQuery;

    if (isFreeEvent(event)) {
        const invitations = (event.products ?? []).reduce((total, product) => total + Number(product.initial_quantity_available ?? 0), 0);
        const registered = Number(eventStats?.total_attendees_registered ?? 0);
        const participants = Number(checkInStats?.total_checked_in_attendees ?? 0);
        const attendanceRate = registered > 0 ? `${Math.round((participants / registered) * 1000) / 10}%` : "0%";

        const freeData = [
            {number: formatNumber(invitations), description: t`Invités`, icon: <IconTicket size={18}/>, backgroundColor: "#7C63E6"},
            {number: formatNumber(registered), description: t`Inscrits`, icon: <IconUsers size={18}/>, backgroundColor: "#E6677E"},
            {number: formatNumber(participants), description: t`Participants`, icon: <IconUserCheck size={18}/>, backgroundColor: "#49A6B7"},
            {number: attendanceRate, description: t`Taux de présence`, icon: <IconUserCheck size={18}/>, backgroundColor: "#63B3A1"},
        ];

        return <div className={classes.statistics}>{freeData.map((stat) => <StatBox key={stat.description} {...stat}/>)}</div>;
    }

    const data = [
        {number: formatNumber(eventStats?.total_attendees_registered as number), description: t`Attendees`, icon: <IconUsers size={18}/>, backgroundColor: "#E6677E"},
        {number: formatNumber(eventStats?.total_products_sold as number), description: t`Products sold`, icon: <IconShoppingCart size={18}/>, backgroundColor: "#4B7BE5"},
        {number: formatCurrency(eventStats?.total_refunded as number || 0, event?.currency), description: t`Refunded`, icon: <IconCreditCardRefund size={18}/>, backgroundColor: "#49A6B7"},
        {number: formatCurrency(eventStats?.total_gross_sales || 0, event?.currency), description: t`Gross sales`, icon: <IconCash size={18}/>, backgroundColor: "#7C63E6"},
        {number: formatNumber(eventStats?.total_views as number), description: t`Page views`, icon: <IconEye size={18}/>, backgroundColor: "#63B3A1"},
        {number: formatNumber(eventStats?.total_orders as number), description: t`Completed orders`, icon: <IconReceipt size={18}/>, backgroundColor: "#E67D49"}
    ];

    return <div className={classes.statistics}>{data.map((stat) => <StatBox key={stat.description} {...stat}/>)}</div>;
};