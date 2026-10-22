import {useState} from "react";
import {useParams} from "react-router";
import {useDebouncedValue} from "@mantine/hooks";
import {t} from "@lingui/macro";
import {ActionIcon, Button, Group, Pagination, Table, Text, TextInput} from "@mantine/core";
import {IconDownload, IconSearch, IconTrash} from "@tabler/icons-react";
import {PageBody} from "../../../common/PageBody";
import {PageTitle} from "../../../common/PageTitle";
import {Card} from "../../../common/Card";
import {NewsletterSubscriber} from "../../../../types.ts";
import {useGetNewsletterSubscribers} from "../../../../queries/useGetNewsletterSubscribers.ts";
import {useDeleteNewsletterSubscriber} from "../../../../mutations/useDeleteNewsletterSubscriber.ts";
import {useGetOrganizer} from "../../../../queries/useGetOrganizer.ts";
import {newsletterSubscriberClient} from "../../../../api/organizer.client.ts";
import {downloadBinary} from "../../../../utilites/download.ts";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../utilites/confirmationDialog.tsx";
import {formatDateWithLocale} from "../../../../utilites/dates.ts";

const sourceLabel = (subscriber: NewsletterSubscriber) => {
    if (subscriber.source === 'REGISTRATION') {
        const eventTitle = subscriber.event_title || t`Deleted event`;
        return t`Registration: ${eventTitle}`;
    }

    return t`Footer`;
};

const NewsletterSubscribers = () => {
    const {organizerId} = useParams();
    const [search, setSearch] = useState('');
    const [debouncedSearch] = useDebouncedValue(search, 300);
    const [page, setPage] = useState(1);
    const [exporting, setExporting] = useState(false);
    const {data: organizer} = useGetOrganizer(organizerId);
    const subscribersQuery = useGetNewsletterSubscribers(organizerId, {query: debouncedSearch || undefined, page});
    const deleteMutation = useDeleteNewsletterSubscriber();
    const subscribers = subscribersQuery.data?.data ?? [];
    const meta = subscribersQuery.data?.meta;
    const timezone = organizer?.timezone || 'UTC';

    const exportSubscribers = async () => {
        setExporting(true);
        try {
            const blob = await newsletterSubscriberClient.export(organizerId!, debouncedSearch || undefined);
            downloadBinary(blob, 'abonnes-lettre-information.xlsx');
            showSuccess(t`Subscribers exported`);
        } catch {
            showError(t`Unable to export the subscribers`);
        } finally {
            setExporting(false);
        }
    };

    const remove = (subscriber: NewsletterSubscriber) => {
        confirmationDialog(
            t`Unsubscribe ${subscriber.email}? Do this when the person asks to unsubscribe or to have their data erased. The address leaves the list and the export, and a past registration will never add it back: only a new consent will.`,
            () => deleteMutation.mutate({organizerId: organizerId!, subscriberId: subscriber.id}, {
                onSuccess: () => showSuccess(t`Subscriber unsubscribed`),
                onError: () => showError(t`Unable to delete the subscriber`),
            })
        );
    };

    return (
        <PageBody>
            <PageTitle
                subheading={t`People who agreed to receive your news, from the footer of your event pages or by ticking the box when registering. You are responsible for this data: only use it for the purpose shown to them, and delete an address when asked.`}>
                {t`Subscribers`}
            </PageTitle>

            <Card>
                <Group gap="sm" mb="md" align="flex-start">
                    <TextInput
                        value={search}
                        onChange={(event) => {
                            setSearch(event.currentTarget.value);
                            setPage(1);
                        }}
                        placeholder={t`Search by e-mail or event`}
                        aria-label={t`Search by e-mail or event`}
                        leftSection={<IconSearch size={16}/>}
                        style={{flex: 1, minWidth: 200}}
                    />
                    <Button
                        variant="light"
                        leftSection={<IconDownload size={16}/>}
                        loading={exporting}
                        disabled={!meta?.total}
                        onClick={exportSubscribers}
                    >
                        {t`Export (XLSX)`}
                    </Button>
                </Group>

                {meta && (
                    <Text size="sm" c="dimmed" mb="sm">
                        {t`${meta.total} subscriber(s)`}
                    </Text>
                )}

                {subscribersQuery.isFetched && subscribers.length === 0 ? (
                    <Text size="sm" c="dimmed">
                        {debouncedSearch ? t`No subscriber matches your search.` : t`No subscriber yet.`}
                    </Text>
                ) : (
                    <Table.ScrollContainer minWidth={720}>
                        <Table verticalSpacing="sm">
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>{t`E-mail`}</Table.Th>
                                    <Table.Th>{t`Source`}</Table.Th>
                                    <Table.Th>{t`Consent date`}</Table.Th>
                                    <Table.Th>{t`Consent text`}</Table.Th>
                                    <Table.Th/>
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {subscribers.map(subscriber => (
                                    <Table.Tr key={subscriber.id}>
                                        <Table.Td><Text size="sm">{subscriber.email}</Text></Table.Td>
                                        <Table.Td>
                                            <Text size="sm">{sourceLabel(subscriber)}</Text>
                                            {subscriber.source !== 'REGISTRATION' && (
                                                <Text size="xs" c="dimmed">{subscriber.event_title || t`Deleted event`}</Text>
                                            )}
                                        </Table.Td>
                                        <Table.Td>
                                            <Text size="sm" style={{whiteSpace: 'nowrap'}}>
                                                {formatDateWithLocale(subscriber.consented_at, 'shortDateTime', timezone)}
                                            </Text>
                                        </Table.Td>
                                        <Table.Td maw={360}>
                                            <Text size="xs" c="dimmed" lineClamp={3} title={subscriber.consent_text}>
                                                {subscriber.consent_text}
                                            </Text>
                                        </Table.Td>
                                        <Table.Td>
                                            <ActionIcon
                                                variant="subtle"
                                                color="red"
                                                onClick={() => remove(subscriber)}
                                                aria-label={t`Delete the subscriber`}
                                                loading={deleteMutation.isPending && deleteMutation.variables?.subscriberId === subscriber.id}
                                            >
                                                <IconTrash size={16}/>
                                            </ActionIcon>
                                        </Table.Td>
                                    </Table.Tr>
                                ))}
                            </Table.Tbody>
                        </Table>
                    </Table.ScrollContainer>
                )}

                {meta && meta.last_page > 1 && (
                    <Group justify="center" mt="md">
                        <Pagination size="sm" value={page} onChange={setPage} total={meta.last_page}/>
                    </Group>
                )}
            </Card>
        </PageBody>
    );
};

export default NewsletterSubscribers;
