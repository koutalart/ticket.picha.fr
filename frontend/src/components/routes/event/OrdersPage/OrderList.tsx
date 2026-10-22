import {useState} from "react";
import {Button, Menu} from "@mantine/core";
import {useClipboard} from "@mantine/hooks";
import {
    IconBasketCog,
    IconCopy,
    IconDots,
    IconReceipt2,
    IconReceiptDollar,
    IconReceiptRefund,
    IconRepeat,
    IconSend,
    IconTrash
} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {Event, IdParam, Invoice, MessageType, Order} from "../../../../types.ts";
import {ManageOrderModal} from "../../../modals/ManageOrderModal";
import {CancelOrderModal} from "../../../modals/CancelOrderModal";
import {SendMessageModal} from "../../../modals/SendMessageModal";
import {RefundOrderModal} from "../../../modals/RefundOrderModal";
import {useResendOrderConfirmation} from "../../../../mutations/useResendOrderConfirmation.ts";
import {useMarkOrderAsPaid} from "../../../../mutations/useMarkOrderAsPaid.ts";
import {useUrlHash} from "../../../../hooks/useUrlHash.ts";
import {orderClient} from "../../../../api/order.client.ts";
import {downloadBinary} from "../../../../utilites/download.ts";
import {withLoadingNotification} from "../../../../utilites/withLoadingNotification.tsx";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {relativeDate} from "../../../../utilites/dates.ts";
import {eventCheckoutUrl} from "../../../../utilites/urlHelper.ts";
import {displayAttendeeEmail} from "../../../../utilites/isKioskSentinelEmail.ts";
import classes from "./OrdersPage.module.scss";

type Modal = 'view' | 'cancel' | 'message' | 'refund';

const statusOf = (order: Order): { label: string, tone: string } => {
    if (order.status === 'CANCELLED') return {label: t`Cancelled`, tone: 'muted'};
    if (order.refund_status === 'REFUNDED') return {label: t`Refunded`, tone: 'muted'};
    if (order.refund_status === 'PARTIALLY_REFUNDED') return {label: t`Partially refunded`, tone: 'warning'};
    if (order.refund_status === 'REFUND_PENDING') return {label: t`Refund pending`, tone: 'warning'};
    if (order.refund_status === 'REFUND_FAILED') return {label: t`Refund failed`, tone: 'danger'};
    if (order.status === 'AWAITING_OFFLINE_PAYMENT' || order.payment_status === 'AWAITING_PAYMENT') {
        return {label: t({message: 'Awaiting payment', context: 'order status'}), tone: 'warning'};
    }
    if (order.payment_status === 'PAYMENT_FAILED') return {label: t`Payment failed`, tone: 'danger'};
    if (order.is_free_order || Number(order.total_gross) === 0) return {label: t({message: 'Free', context: 'order status'}), tone: 'success'};
    return {label: t({message: 'Paid', context: 'order status'}), tone: 'success'};
};

const itemsOf = (order: Order) => (order.order_items ?? [])
    .map((item) => `${item.quantity} × ${item.item_name}`)
    .join(', ');

export const OrderList = ({orders, event}: { orders: Order[], event: Event }) => {
    const [orderId, setOrderId] = useState<IdParam>();
    const [modal, setModal] = useState<Modal | null>(null);
    const clipboard = useClipboard({timeout: 2000});
    const resendMutation = useResendOrderConfirmation();
    const markAsPaidMutation = useMarkOrderAsPaid();

    const open = (id: IdParam, which: Modal) => {
        setOrderId(id);
        setModal(which);
    };
    const close = () => setModal(null);

    useUrlHash(/^#order-(\d+)$/, (matches) => open(matches![1], 'view'));

    const markAsPaid = (order: Order) => markAsPaidMutation.mutate({eventId: event.id, orderId: order.id}, {
        onSuccess: () => showSuccess(t`Order marked as paid`),
        onError: () => showError(t`There was an error marking the order as paid`),
    });

    const resend = (order: Order) => resendMutation.mutate({eventId: event.id, orderId: order.id}, {
        onSuccess: () => showSuccess(t`Your message has been sent`),
        onError: () => showError(t`There was an error sending your message`),
    });

    const downloadInvoice = (invoice: Invoice) => withLoadingNotification(async () => {
        const blob = await orderClient.downloadInvoice(event.id, invoice.order_id);
        downloadBinary(blob, invoice.invoice_number + '.pdf');
    }, {
        loading: {title: t`Downloading Invoice`, message: t`Please wait while we prepare your invoice...`},
        success: {title: t`Success`, message: t`Invoice downloaded successfully`},
        error: {title: t`Error`, message: t`Failed to download invoice. Please try again.`},
    });

    const actions = (order: Order) => {
        const refundable = !order.is_free_order
            && order.status !== 'AWAITING_OFFLINE_PAYMENT'
            && order.payment_provider === 'STRIPE'
            && order.refund_status !== 'REFUNDED';

        return (
            <Menu shadow="md" width={230} position="bottom-end">
                <Menu.Target>
                    <button type="button" className={classes.moreButton} aria-label={t`More actions`}
                            onClick={(event) => event.stopPropagation()}>
                        <IconDots size={20}/>
                    </button>
                </Menu.Target>
                <Menu.Dropdown onClick={(event) => event.stopPropagation()}>
                    <Menu.Item leftSection={<IconBasketCog size={14}/>} onClick={() => open(order.id, 'view')}>
                        {t`Manage order`}
                    </Menu.Item>
                    <Menu.Item leftSection={<IconSend size={14}/>} onClick={() => open(order.id, 'message')}>
                        {t`Message buyer`}
                    </Menu.Item>
                    <Menu.Item leftSection={<IconCopy size={14}/>} onClick={() => {
                        clipboard.copy(eventCheckoutUrl(order.event_id, order.short_id, 'summary', event.organizer));
                        showSuccess(t`Customer link copied to clipboard`);
                    }}>
                        {t`Copy customer link`}
                    </Menu.Item>
                    {order.latest_invoice && (
                        <Menu.Item leftSection={<IconReceipt2 size={14}/>}
                                   onClick={() => downloadInvoice(order.latest_invoice as Invoice)}>
                            {t`Download invoice`}
                        </Menu.Item>
                    )}
                    {order.status === 'AWAITING_OFFLINE_PAYMENT' && (
                        <Menu.Item leftSection={<IconReceiptDollar size={14}/>} onClick={() => markAsPaid(order)}>
                            {t`Mark as paid`}
                        </Menu.Item>
                    )}
                    {refundable && (
                        <Menu.Item leftSection={<IconReceiptRefund size={14}/>} onClick={() => open(order.id, 'refund')}>
                            {t`Refund order`}
                        </Menu.Item>
                    )}
                    {order.status === 'COMPLETED' && (
                        <Menu.Item leftSection={<IconRepeat size={14}/>} onClick={() => resend(order)}>
                            {t`Resend order email`}
                        </Menu.Item>
                    )}
                    {order.status !== 'CANCELLED' && (
                        <>
                            <Menu.Divider/>
                            <Menu.Item color="red" leftSection={<IconTrash size={14}/>} onClick={() => open(order.id, 'cancel')}>
                                {t`Cancel order`}
                            </Menu.Item>
                        </>
                    )}
                </Menu.Dropdown>
            </Menu>
        );
    };

    return (
        <>
            <div className={classes.list}>
                <div className={classes.listHead}>
                    <span><Trans>Customer</Trans></span>
                    <span><Trans>Tickets</Trans></span>
                    <span className={classes.alignRight}><Trans>Amount</Trans></span>
                    <span><Trans>Status</Trans></span>
                    <span/>
                </div>

                {orders.map((order) => {
                    const status = statusOf(order);
                    const email = displayAttendeeEmail(order.email);
                    return (
                        <div key={order.id} className={classes.row} role="button" tabIndex={0}
                             onClick={() => open(order.id, 'view')}
                             onKeyDown={(event) => event.key === 'Enter' && open(order.id, 'view')}>
                            <div className={classes.customer}>
                                <span className={classes.customerName}>
                                    {[order.first_name, order.last_name].filter(Boolean).join(' ') || t`No name`}
                                </span>
                                <span className={classes.customerMeta}>
                                    #{order.public_id} · {relativeDate(order.created_at)} ·{' '}
                                    <span className={order.is_manually_created ? classes.channelBoxOffice : classes.channelOnline}>
                                        {order.is_manually_created ? t`Box Office` : t`Online`}
                                    </span>
                                </span>
                                {email && <span className={classes.customerEmail}>{email}</span>}
                            </div>
                            <div className={classes.items}>{itemsOf(order)}</div>
                            <div className={classes.amount}>
                                {formatCurrency(order.total_gross, order.currency)}
                                {Number(order.total_refunded) > 0 && (
                                    <span className={classes.refunded}>
                                        −{formatCurrency(order.total_refunded, order.currency)}
                                    </span>
                                )}
                            </div>
                            <div className={classes.status}>
                                <span className={`${classes.pill} ${classes[`pill_${status.tone}`]}`}>{status.label}</span>
                                {order.status === 'AWAITING_OFFLINE_PAYMENT' && (
                                    <Button size="compact-xs" variant="subtle" loading={markAsPaidMutation.isPending}
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                markAsPaid(order);
                                            }}>
                                        <Trans>Mark as paid</Trans>
                                    </Button>
                                )}
                            </div>
                            <div className={classes.actions}>{actions(order)}</div>
                        </div>
                    );
                })}
            </div>

            {orderId && modal === 'view' && <ManageOrderModal onClose={close} orderId={orderId}/>}
            {orderId && modal === 'refund' && <RefundOrderModal onClose={close} orderId={orderId}/>}
            {orderId && modal === 'cancel' && <CancelOrderModal onClose={close} orderId={orderId}/>}
            {orderId && modal === 'message' && (
                <SendMessageModal onClose={close} orderId={orderId} messageType={MessageType.OrderOwner}/>
            )}
        </>
    );
};
