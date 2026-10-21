import {useState} from "react";
import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {useDebouncedValue} from "@mantine/hooks";
import {t} from "@lingui/macro";
import {ActionIcon, Badge, Button, Group, Pagination, Table, Text, TextInput} from "@mantine/core";
import {IconBan, IconSearch, IconTrash} from "@tabler/icons-react";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {SmsOptOut} from "../../../../../../types.ts";
import {useGetSmsOptOuts} from "../../../../../../queries/useGetSmsOptOuts.ts";
import {useAddSmsOptOut} from "../../../../../../mutations/useAddSmsOptOut.ts";
import {useRemoveSmsOptOut} from "../../../../../../mutations/useRemoveSmsOptOut.ts";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {showError, showSuccess} from "../../../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../../../utilites/confirmationDialog.tsx";
import {relativeDate} from "../../../../../../utilites/dates.ts";

export const SmsOptOuts = () => {
    const {organizerId} = useParams();
    const [search, setSearch] = useState('');
    const [debouncedSearch] = useDebouncedValue(search, 300);
    const [page, setPage] = useState(1);
    const optOutsQuery = useGetSmsOptOuts(organizerId, {query: debouncedSearch || undefined, page});
    const addMutation = useAddSmsOptOut();
    const removeMutation = useRemoveSmsOptOut();
    const formErrorHandle = useFormErrorResponseHandler();
    const optOuts = optOutsQuery.data?.data ?? [];
    const meta = optOutsQuery.data?.meta;

    const form = useForm({
        initialValues: {phone: ''},
    });

    const add = (values: typeof form.values) => {
        addMutation.mutate({organizerId: organizerId!, phone: values.phone.trim()}, {
            onSuccess: () => {
                form.reset();
                showSuccess(t`Number added to the opt-out list`);
            },
            onError: (error) => formErrorHandle(form, error),
        });
    };

    const remove = (optOut: SmsOptOut) => {
        confirmationDialog(t`Remove ${optOut.phone} from the opt-out list? Only do this if the person asked to receive your SMS again.`, () => {
            removeMutation.mutate({organizerId: organizerId!, optOutId: optOut.id}, {
                onSuccess: () => showSuccess(t`Number removed from the opt-out list`),
                onError: () => showError(t`Unable to remove the number`),
            });
        });
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`SMS opt-out list`}
                description={t`Numbers that replied STOP or that you added by hand. They no longer receive any SMS from you. A number that replies START is removed automatically.`}
            />

            <form onSubmit={form.onSubmit(add)}>
                <Group align="flex-start" gap="sm" mb="md">
                    <TextInput
                        {...form.getInputProps('phone')}
                        placeholder="+262639123456"
                        aria-label={t`Phone number`}
                        style={{flex: 1}}
                        maxLength={30}
                    />
                    <Button type="submit" variant="light" leftSection={<IconBan size={16}/>} loading={addMutation.isPending}>
                        {t`Add the number`}
                    </Button>
                </Group>
            </form>

            <TextInput
                value={search}
                onChange={(event) => {
                    setSearch(event.currentTarget.value);
                    setPage(1);
                }}
                placeholder={t`Search by number or name`}
                leftSection={<IconSearch size={16}/>}
                mb="sm"
            />

            {optOutsQuery.isFetched && optOuts.length === 0 ? (
                <Text size="sm" c="dimmed">
                    {debouncedSearch ? t`No number matches your search.` : t`No number in the opt-out list.`}
                </Text>
            ) : (
                <Table.ScrollContainer minWidth={420}>
                    <Table verticalSpacing="xs">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>{t`Number`}</Table.Th>
                                <Table.Th>{t`Source`}</Table.Th>
                                <Table.Th>{t`Since`}</Table.Th>
                                <Table.Th/>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {optOuts.map(optOut => (
                                <Table.Tr key={optOut.id}>
                                    <Table.Td>
                                        <Text size="sm">{optOut.phone}</Text>
                                        {optOut.contact_name && <Text size="xs" c="dimmed">{optOut.contact_name}</Text>}
                                    </Table.Td>
                                    <Table.Td>
                                        <Badge variant="light" color={optOut.source === 'KEYWORD' ? 'red' : 'gray'}>
                                            {optOut.source === 'KEYWORD' ? t`Replied STOP` : t`Added by hand`}
                                        </Badge>
                                    </Table.Td>
                                    <Table.Td>
                                        <Text size="sm" c="dimmed">{relativeDate(optOut.created_at)}</Text>
                                    </Table.Td>
                                    <Table.Td>
                                        <ActionIcon variant="subtle" color="red" onClick={() => remove(optOut)} aria-label={t`Remove`}>
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
    );
};
