import {t} from "@lingui/macro";
import {Anchor, Badge, Text} from "@mantine/core";
import {NavLink} from "react-router";
import {SmsConversationDetail} from "../../../../types.ts";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {formatDateWithLocale} from "../../../../utilites/dates.ts";
import classes from "./Sms.module.scss";

interface CustomerCardProps {
    detail: SmsConversationDetail | undefined;
    timezone: string;
}

export const CustomerCard = ({detail, timezone}: CustomerCardProps) => {
    if (!detail) {
        return <div className={classes.customerColumn}/>;
    }

    const orders = detail.customer_orders;
    const first = orders[0];
    const name = first ? [first.first_name, first.last_name].filter(Boolean).join(' ') : null;
    const emails = Array.from(new Set(orders.map(order => order.email).filter(Boolean)));
    const events = Array.from(new Set(orders.map(order => order.event_title)));

    return (
        <div className={classes.customerColumn}>
            <Text fw={700} mb={4}>{name || t`Unknown customer`}</Text>
            <Text size="sm" c="dimmed">{detail.conversation.phone}</Text>
            {emails.map(email => <Text key={email} size="sm" c="dimmed">{email}</Text>)}

            {detail.opted_out && (
                <Badge color="red" variant="light" mt="sm">{t`Unsubscribed (STOP)`}</Badge>
            )}

            {orders.length === 0 ? (
                <Text size="sm" c="dimmed" mt="md">
                    {t`No order found with this number. The number is matched with the phone answers of your order forms and box office sales.`}
                </Text>
            ) : (
                <>
                    <Text size="sm" fw={600} mt="md" mb={4}>
                        {t`${orders.length} orders, ${events.length} events`}
                    </Text>
                    <div className={classes.orders}>
                        {orders.map(order => (
                            <div key={order.order_id} className={classes.order}>
                                <Anchor
                                    component={NavLink}
                                    to={`/manage/event/${order.event_id}/orders?query=${encodeURIComponent(order.public_id)}`}
                                    size="sm"
                                    fw={600}
                                >
                                    {order.event_title}
                                </Anchor>
                                <Text size="xs" c="dimmed">
                                    {order.public_id}
                                    {order.event_start_date ? `, ${formatDateWithLocale(order.event_start_date, 'shortDate', timezone)}` : ''}
                                </Text>
                                <Text size="xs">
                                    {formatCurrency(order.total_gross, order.currency)}
                                    {order.status === 'CANCELLED' ? ` (${t`cancelled`})` : ''}
                                    {order.status === 'AWAITING_OFFLINE_PAYMENT' ? ` (${t`awaiting payment`})` : ''}
                                </Text>
                            </div>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
};
