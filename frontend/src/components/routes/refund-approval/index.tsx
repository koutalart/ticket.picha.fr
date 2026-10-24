import {useState} from "react";
import {useParams, useSearchParams} from "react-router";
import {t} from "@lingui/macro";
import {Alert, Button, Loader, Text} from "@mantine/core";
import {IconCheck, IconX} from "@tabler/icons-react";
import {RefundApproval as RefundApprovalData, RefundDecision} from "../../../types.ts";
import {useGetRefundApproval} from "../../../queries/useGetRefundApproval.ts";
import {useDecideRefund} from "../../../mutations/useDecideRefund.ts";
import {formatDate} from "../../../utilites/dates.ts";
import classes from "./RefundApproval.module.scss";

const statusText = (approval: RefundApprovalData): string => {
    switch (approval.status) {
        case 'AWAITING_APPROVAL':
            return t`Waiting for your decision`;
        case 'REFUNDED':
            return t`Approved and refunded`;
        case 'REFUSED':
            return t`Refused, the customer is informed`;
        case 'PROCESSING':
            return t`Approved, refund in progress`;
        default:
            return t`Approved, a person finishes the refund`;
    }
};

const channelText = (channel: RefundApprovalData['decided_by_channel']): string => {
    switch (channel) {
        case 'SMS':
            return t`by SMS`;
        case 'LINK':
            return t`with the link`;
        case 'DASHBOARD':
            return t`from the dashboard`;
        default:
            return '';
    }
};

const RefundApproval = () => {
    const {requestId, organizerId} = useParams();
    const [searchParams] = useSearchParams();
    const token = searchParams.get('token');
    const query = useGetRefundApproval(requestId, token, organizerId);
    const decideMutation = useDecideRefund();
    const [pending, setPending] = useState<RefundDecision | null>(null);
    const [error, setError] = useState<string | null>(null);
    const approval = query.data;

    const decide = (decision: RefundDecision) => {
        setPending(decision);
        setError(null);
        decideMutation.mutate({requestId: requestId!, token, organizerId, decision}, {
            onError: (requestError: any) => setError(requestError?.response?.data?.message || t`Something went wrong. Please try again.`),
            onSettled: () => setPending(null),
        });
    };

    return (
        <div className={classes.page}>
            <div className={classes.card}>
                {query.isLoading && <Loader size="sm"/>}

                {query.isError && (
                    <Alert color="red" variant="light">
                        {t`This link is not valid or has expired. Log in to your account to handle the request.`}
                    </Alert>
                )}

                {approval && (
                    <>
                        <h1 className={classes.title}>{t`Refund request ${approval.reference}`}</h1>
                        <Text size="sm" c="dimmed">{approval.organizer_name}</Text>

                        <div className={classes.details}>
                            <span className={classes.label}>{t`Customer`}</span>
                            <span className={classes.value}>{approval.customer_name}</span>
                            <span className={classes.label}>{t`Order`}</span>
                            <span className={classes.value}>{approval.order_reference ?? ''}</span>
                            {approval.event_title && (
                                <>
                                    <span className={classes.label}>{t`Event`}</span>
                                    <span className={classes.value}>{approval.event_title}</span>
                                </>
                            )}
                            <span className={classes.label}>{t`Amount`}</span>
                            <span className={classes.value}>{approval.amount_text}</span>
                            {approval.requested_at && (
                                <>
                                    <span className={classes.label}>{t`Asked on`}</span>
                                    <span className={classes.value}>{formatDate(approval.requested_at, 'DD/MM/YYYY HH:mm', 'Indian/Mayotte')}</span>
                                </>
                            )}
                            <span className={classes.label}>{t`Status`}</span>
                            <span className={classes.value}>{statusText(approval)}</span>
                            {approval.decided_at && (
                                <>
                                    <span className={classes.label}>{t`Decided`}</span>
                                    <span className={classes.value}>
                                        {[approval.decided_by_name, channelText(approval.decided_by_channel)].filter(Boolean).join(', ')}
                                    </span>
                                </>
                            )}
                        </div>

                        {error && <Alert color="red" variant="light" mb="md">{error}</Alert>}

                        {approval.can_decide && (
                            <>
                                <Text size="sm" mb="md">
                                    {t`The first answer decides. If you approve, the ticket price is refunded to the customer's card at once.`}
                                </Text>
                                <div className={classes.actions}>
                                    <Button
                                        color="green"
                                        leftSection={<IconCheck size={16}/>}
                                        loading={pending === 'APPROVED'}
                                        disabled={pending !== null}
                                        onClick={() => decide('APPROVED')}
                                    >
                                        {t`Approve`}
                                    </Button>
                                    <Button
                                        color="red"
                                        variant="light"
                                        leftSection={<IconX size={16}/>}
                                        loading={pending === 'REFUSED'}
                                        disabled={pending !== null}
                                        onClick={() => decide('REFUSED')}
                                    >
                                        {t`Refuse`}
                                    </Button>
                                </div>
                            </>
                        )}
                    </>
                )}
            </div>
        </div>
    );
};

export default RefundApproval;
