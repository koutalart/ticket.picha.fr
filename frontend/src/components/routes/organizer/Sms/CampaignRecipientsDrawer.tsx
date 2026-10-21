import {useMemo, useState} from "react";
import {t} from "@lingui/macro";
import {Badge, Drawer, SegmentedControl, Skeleton, Stack, Table, Text, TextInput} from "@mantine/core";
import {IconSearch} from "@tabler/icons-react";
import {IdParam, SmsCampaign, SmsCampaignRecipient} from "../../../../types.ts";
import {useGetSmsCampaignRecipients} from "../../../../queries/useGetSmsCampaignRecipients.ts";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {recipientStatusColor, recipientStatusLabel} from "./labels.ts";
import classes from "./Sms.module.scss";

type RecipientFilter = 'all' | 'clicked' | 'ordered' | 'failed';

interface CampaignRecipientsDrawerProps {
    organizerId: IdParam;
    campaign: SmsCampaign | null;
    currency: string;
    onClose: () => void;
}

const matchesFilter = (recipient: SmsCampaignRecipient, filter: RecipientFilter) => {
    switch (filter) {
        case 'clicked':
            return recipient.has_clicked;
        case 'ordered':
            return recipient.orders_count > 0;
        case 'failed':
            return recipient.status === 'FAILED' || recipient.status === 'EXPIRED';
        default:
            return true;
    }
};

export const CampaignRecipientsDrawer = ({organizerId, campaign, currency, onClose}: CampaignRecipientsDrawerProps) => {
    const recipientsQuery = useGetSmsCampaignRecipients(organizerId, campaign?.id);
    const [filter, setFilter] = useState<RecipientFilter>('all');
    const [search, setSearch] = useState('');

    const recipients = useMemo(() => {
        const needle = search.trim().toLowerCase();
        return (recipientsQuery.data ?? []).filter(recipient => matchesFilter(recipient, filter)
            && (!needle || `${recipient.contact_name ?? ''} ${recipient.phone}`.toLowerCase().includes(needle)));
    }, [recipientsQuery.data, filter, search]);

    return (
        <Drawer
            opened={!!campaign}
            onClose={onClose}
            position="right"
            size="xl"
            title={<Text fw={700}>{campaign?.name}</Text>}
        >
            <Stack gap="md">
                <SegmentedControl
                    value={filter}
                    onChange={(value) => setFilter(value as RecipientFilter)}
                    data={[
                        {value: 'all', label: t`All`},
                        {value: 'clicked', label: t`Clicked`},
                        {value: 'ordered', label: t`Bought`},
                        {value: 'failed', label: t`Not delivered`},
                    ]}
                />
                <TextInput
                    leftSection={<IconSearch size={16}/>}
                    placeholder={t`Search a name or a number…`}
                    value={search}
                    onChange={(event) => setSearch(event.currentTarget.value)}
                />

                {recipientsQuery.isLoading && <Skeleton height={300}/>}

                {!recipientsQuery.isLoading && recipients.length === 0 && (
                    <Text size="sm" c="dimmed">{t`No recipient matches this filter.`}</Text>
                )}

                {recipients.length > 0 && (
                    <Table.ScrollContainer minWidth={560}>
                        <Table verticalSpacing="xs" striped>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>{t`Recipient`}</Table.Th>
                                    <Table.Th>{t`SMS`}</Table.Th>
                                    <Table.Th>{t`Clicked`}</Table.Th>
                                    <Table.Th>{t`Orders`}</Table.Th>
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {recipients.map(recipient => (
                                    <Table.Tr key={recipient.id}>
                                        <Table.Td>
                                            <div className={classes.recipientName}>{recipient.contact_name || recipient.phone}</div>
                                            {recipient.contact_name && <Text size="xs" c="dimmed">{recipient.phone}</Text>}
                                        </Table.Td>
                                        <Table.Td>
                                            <Badge variant="light" color={recipientStatusColor(recipient.status)}>
                                                {recipientStatusLabel(recipient.status)}
                                            </Badge>
                                            {recipient.failure_reason && (
                                                <Text size="xs" c="dimmed" mt={2}>{recipient.failure_reason}</Text>
                                            )}
                                        </Table.Td>
                                        <Table.Td>
                                            {recipient.has_clicked
                                                ? <Badge variant="light" color="teal">{t`Yes`} ({recipient.visits_count})</Badge>
                                                : <Text size="sm" c="dimmed">{t`No`}</Text>}
                                        </Table.Td>
                                        <Table.Td>
                                            {recipient.orders_count > 0
                                                ? `${recipient.orders_count} (${formatCurrency(recipient.revenue_gross, currency)})`
                                                : <Text size="sm" c="dimmed">0</Text>}
                                        </Table.Td>
                                    </Table.Tr>
                                ))}
                            </Table.Tbody>
                        </Table>
                    </Table.ScrollContainer>
                )}
            </Stack>
        </Drawer>
    );
};
