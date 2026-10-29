import React, {useState} from "react";
import {useLocation, useNavigate, useParams} from "react-router";
import {REFUND_PROTECTION_OFFERED_STATE} from "../../../../utilites/refundProtection.ts";
import {useGetEventPublic} from "../../../../queries/useGetEventPublic.ts";
import {CheckoutContent} from "../../../layouts/Checkout/CheckoutContent";
import {StripePaymentMethod} from "./PaymentMethods/Stripe";
import {OfflinePaymentMethod} from "./PaymentMethods/Offline";
import {Event} from "../../../../types.ts";
import {Button, Group, Text} from "@mantine/core";
import {IconBuildingBank, IconLock, IconWallet} from "@tabler/icons-react";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {t, Trans} from "@lingui/macro";
import {useGetOrderPublic} from "../../../../queries/useGetOrderPublic.ts";
import {
    useTransitionOrderToOfflinePaymentPublic
} from "../../../../mutations/useTransitionOrderToOfflinePaymentPublic.ts";
import {Card} from "../../../common/Card";
import {InlineOrderSummary} from "../../../common/InlineOrderSummary";
import {showError} from "../../../../utilites/notifications.tsx";
import {getConfig} from "../../../../utilites/config.ts";
import classes from "./Payment.module.scss";
import {trackEvent, AnalyticsEvents} from "../../../../utilites/analytics.ts";
import {getAppName, getTermsOfSaleUrl} from "../../../../utilites/branding.ts";
import {LoadingMask} from "../../../common/LoadingMask";
import {useIsFetching} from "@tanstack/react-query";
import {useGetOrderRefundProtectionPublic} from "../../../../queries/useGetOrderRefundProtectionPublic.ts";
import {useUpdateOrderRefundProtectionPublic} from "../../../../mutations/useUpdateOrderRefundProtectionPublic.ts";
import {GET_INITIATE_STRIPE_SESSION_PUBLIC_QUERY_KEY} from "../../../../queries/useCreateStripePaymentIntent.ts";
import {RefundProtectionOption} from "./RefundProtectionOption";

const Payment = () => {
    const navigate = useNavigate();
    const {eventId, orderShortId} = useParams();
    const location = useLocation();
    const refundProtectionAlreadyOffered = !!(location.state as Record<string, unknown> | null)?.[REFUND_PROTECTION_OFFERED_STATE];
    const {data: event, isFetched: isEventFetched} = useGetEventPublic(eventId);
    const {data: order, isFetched: isOrderFetched} = useGetOrderPublic(eventId, orderShortId, ['event']);
    const isLoading = !isOrderFetched;
    const [isPaymentLoading, setIsPaymentLoading] = useState(false);
    const [activePaymentMethod, setActivePaymentMethod] = useState<'STRIPE' | 'OFFLINE' | null>(null);
    const [submitHandler, setSubmitHandler] = useState<(() => Promise<void>) | null>(null);
    const transitionOrderToOfflinePaymentMutation = useTransitionOrderToOfflinePaymentPublic();
    const {data: refundProtection} = useGetOrderRefundProtectionPublic(eventId, orderShortId, !!order?.is_payment_required);
    const refundProtectionMutation = useUpdateOrderRefundProtectionPublic();
    const isStripeIntentFetching = useIsFetching({queryKey: [GET_INITIATE_STRIPE_SESSION_PUBLIC_QUERY_KEY, eventId, orderShortId]}) > 0;
    const isRefundProtectionChecked = refundProtectionMutation.isPending
        ? !!refundProtectionMutation.variables?.enabled
        : !!refundProtection?.is_selected;

    const handleRefundProtectionChange = (enabled: boolean) => {
        refundProtectionMutation.mutate({eventId, orderShortId, enabled}, {
            onError: (error: any) => {
                showError(error.response?.data?.errors?.enabled?.[0]
                    || error.response?.data?.message
                    || t`Unable to update the peace of mind option. Please try again.`);
            },
        });
    };

    const isStripeEnabled = event?.settings?.payment_providers?.includes('STRIPE');
    const isOfflineEnabled = event?.settings?.payment_providers?.includes('OFFLINE');

    React.useEffect(() => {
        // Automatically set the first available payment method
        if (isStripeEnabled) {
            setActivePaymentMethod('STRIPE');
        } else if (isOfflineEnabled) {
            setActivePaymentMethod('OFFLINE');
        } else {
            setActivePaymentMethod(null); // No methods available
        }
    }, [isStripeEnabled, isOfflineEnabled]);

    React.useEffect(() => {
        // Free orders never need to enter the payment step.
        if (isOrderFetched && order && !order.is_payment_required) {
            navigate(`/checkout/${eventId}/${orderShortId}/summary`, {replace: true});
        }
    }, [isOrderFetched, order?.is_payment_required, eventId, orderShortId, navigate]);

    React.useEffect(() => {
        // Scroll to top when payment page loads
        window?.scrollTo(0, 0);
    }, []);

    const handleParentSubmit = () => {
        if (submitHandler) {
            setIsPaymentLoading(true);
            submitHandler().finally(() => setIsPaymentLoading(false));
        }
    };

    const handleSubmit = async () => {
        if (activePaymentMethod === 'STRIPE') {
            handleParentSubmit();
        } else if (activePaymentMethod === 'OFFLINE') {
            setIsPaymentLoading(true);

            await transitionOrderToOfflinePaymentMutation.mutateAsync({
                eventId,
                orderShortId
            }, {
                onSuccess: () => {
                    const totalCents = Math.round((order?.total_gross || 0) * 100);
                    trackEvent(AnalyticsEvents.PURCHASE_COMPLETED_OFFLINE, { value: totalCents });
                    navigate(`/checkout/${eventId}/${orderShortId}/summary`);
                },
                onError: (error: any) => {
                    setIsPaymentLoading(false);
                    showError(error.response?.data?.message || t`Offline payment failed. Please try again or contact the event organizer.`);
                }
            });
        }
    };

    if (isOrderFetched && order && !order.is_payment_required) {
        return <LoadingMask />;
    }

    if (!isStripeEnabled && !isOfflineEnabled && isOrderFetched && isEventFetched) {
        return (
            <CheckoutContent>
                <Card>
                    {t`No payment methods are currently available. Please contact the event organizer for assistance.`}
                </Card>
            </CheckoutContent>
        );
    }

    return (
        <>
            <CheckoutContent>
                {(event && order) && (
                    <InlineOrderSummary event={event} order={order} defaultExpanded={false}/>
                )}
                {refundProtection && !refundProtectionAlreadyOffered && (
                    <RefundProtectionOption
                        offer={refundProtection}
                        checked={isRefundProtectionChecked}
                        disabled={refundProtectionMutation.isPending || isStripeIntentFetching || isPaymentLoading}
                        onChange={handleRefundProtectionChange}
                        deadlineHours={event?.settings?.refund_protection_deadline_hours ?? 0}
                    />
                )}
                {isStripeEnabled && (
                    <div style={{display: activePaymentMethod === 'STRIPE' ? 'block' : 'none'}}>
                        <StripePaymentMethod enabled={true} event={event} order={order} setSubmitHandler={setSubmitHandler}/>
                    </div>
                )}

                {isOfflineEnabled && (
                    <div style={{display: activePaymentMethod === 'OFFLINE' ? 'block' : 'none'}}>
                        <OfflinePaymentMethod event={event as Event}/>
                    </div>
                )}

                {(isStripeEnabled && isOfflineEnabled) && (
                    <div className={classes.paymentMethodSelector}>
                        <Text size="sm" c="dimmed" className={classes.paymentMethodLabel}>
                            {t`Payment method`}
                        </Text>
                        <div className={classes.paymentMethodTabs}>
                            <button
                                type="button"
                                className={`${classes.paymentMethodTab} ${activePaymentMethod === 'STRIPE' ? classes.active : ''}`}
                                onClick={() => setActivePaymentMethod('STRIPE')}
                            >
                                <IconWallet size={18}/>
                                <span>{t`Online`}</span>
                            </button>
                            <button
                                type="button"
                                className={`${classes.paymentMethodTab} ${activePaymentMethod === 'OFFLINE' ? classes.active : ''}`}
                                onClick={() => setActivePaymentMethod('OFFLINE')}
                            >
                                <IconBuildingBank size={18}/>
                                <span>{t`Offline`}</span>
                            </button>
                        </div>
                    </div>
                )}

                <div className={classes.checkoutActions}>
                    <Button
                        className={classes.continueButton}
                        loading={isLoading || isPaymentLoading || refundProtectionMutation.isPending || isStripeIntentFetching}
                        onClick={handleSubmit}
                    >
                        {order?.is_payment_required ? (
                            <Group gap={8} wrap="nowrap">
                                <IconLock size={16}/>
                                <Text fw={600}>{t`Pay`} {formatCurrency(order.total_gross, order.currency)}</Text>
                            </Group>
                        ) : t`Complete Payment`}
                    </Button>
                    {getConfig('VITE_TOS_URL') && (
                        <p className={classes.tosNotice}>
                            <Trans>
                                By continuing, you agree to the{' '}
                                <a
                                    href={getTermsOfSaleUrl() as string}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {getAppName()} Terms of Service
                                </a>
                            </Trans>
                        </p>
                    )}
                </div>
            </CheckoutContent>
        </>
    );
}

export default Payment;
