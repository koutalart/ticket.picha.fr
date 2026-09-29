import {Badge, Button, Group, Table} from "@mantine/core";
import {t} from "@lingui/macro";
import {IconPlus} from "@tabler/icons-react";
import {Navigate, useParams} from "react-router";
import {useDisclosure} from "@mantine/hooks";
import {PageBody} from "../../../../common/PageBody";
import {PageTitle} from "../../../../common/PageTitle";
import {ToolBar} from "../../../../common/ToolBar";
import {TableSkeleton} from "../../../../common/TableSkeleton";
import {NoResultsSplash} from "../../../../common/NoResultsSplash";
import {Card} from "../../../../common/Card";
import {InviteBoxOfficeOperatorModal} from "../../../../modals/InviteBoxOfficeOperatorModal";
import {useGetMe} from "../../../../../queries/useGetMe.ts";
import {useGetBoxOfficeOperators} from "../../../../../queries/useGetBoxOfficeOperators.ts";
import {useUpdateBoxOfficeOperator} from "../../../../../mutations/useUpdateBoxOfficeOperator.ts";
import {showError, showSuccess} from "../../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../../utilites/confirmationDialog.tsx";
import {BoxOfficeOperator} from "../../../../../api/box-office.client.ts";

const BoxOfficeOperators = () => {
    const {eventId} = useParams();
    const {data: me, isFetched} = useGetMe();
    const isAdmin = me?.role === 'ADMIN' || me?.role === 'SUPERADMIN';
    const operatorsQuery = useGetBoxOfficeOperators(eventId, isFetched && isAdmin);
    const updateOperator = useUpdateBoxOfficeOperator();
    const [inviteOpen, {open: openInvite, close: closeInvite}] = useDisclosure(false);

    const operators = operatorsQuery.data?.data;

    const setStatus = (operator: BoxOfficeOperator, status: 'ACTIVE' | 'REVOKED') => {
        updateOperator.mutate({
            eventId: eventId!,
            userId: operator.user_id,
            status,
        }, {
            onSuccess: () => {
                showSuccess(status === 'REVOKED'
                    ? t`Access revoked for this event`
                    : t`Operator restored for this event`);
            },
            onError: (error: any) => {
                showError(error?.response?.data?.message || t`Something went wrong. Please try again.`);
            },
        });
    };

    const handleRevoke = (operator: BoxOfficeOperator) => {
        confirmationDialog(
            t`Revoke this operator for this event? They will no longer be able to sell tickets here. Past sales stay in the audit trail.`,
            () => setStatus(operator, 'REVOKED'),
            {confirm: t`Revoke`},
        );
    };

    if (!isFetched) {
        return (
            <PageBody>
                <TableSkeleton isVisible/>
            </PageBody>
        );
    }

    if (!isAdmin) {
        return <Navigate to={`/manage/event/${eventId}/box-office`} replace/>;
    }

    return (
        <PageBody>
            <PageTitle
                subheading={t`Invite people who can sell tickets at the door. They only see the kiosk, not this back office.`}
            >
                {t`Kiosk operators`}
            </PageTitle>

            <ToolBar>
                <Button leftSection={<IconPlus/>} color={'green'} onClick={openInvite}>
                    {t`Invite operator`}
                </Button>
            </ToolBar>

            <TableSkeleton isVisible={operatorsQuery.isLoading}/>

            {operators && operators.length === 0 && (
                <NoResultsSplash
                    heading={t`No kiosk operators yet`}
                    subHeading={t`Invite someone to sell tickets at the door. They will receive an email to set their password, then sign in at the kiosk.`}
                >
                    <Button onClick={openInvite}>{t`Invite operator`}</Button>
                </NoResultsSplash>
            )}

            {!!operators?.length && (
                <Card style={{padding: 0}}>
                    <Table.ScrollContainer minWidth={700}>
                        <Table>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>{t`Name`}</Table.Th>
                                    <Table.Th>{t`Email`}</Table.Th>
                                    <Table.Th>{t`Status`}</Table.Th>
                                    <Table.Th/>
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {operators.map((operator) => (
                                    <Table.Tr key={operator.user_id}>
                                        <Table.Td>
                                            {[operator.first_name, operator.last_name].filter(Boolean).join(' ') || '—'}
                                        </Table.Td>
                                        <Table.Td>{operator.email}</Table.Td>
                                        <Table.Td>
                                            <Badge
                                                variant="light"
                                                color={operator.status === 'ACTIVE' ? 'green' : 'red'}
                                            >
                                                {operator.status === 'ACTIVE' ? t`Active` : t`Revoked`}
                                            </Badge>
                                        </Table.Td>
                                        <Table.Td>
                                            <Group justify="flex-end">
                                                {operator.status === 'ACTIVE' ? (
                                                    <Button
                                                        variant="subtle"
                                                        color="red"
                                                        size="compact-sm"
                                                        loading={updateOperator.isPending}
                                                        onClick={() => handleRevoke(operator)}
                                                    >
                                                        {t`Revoke`}
                                                    </Button>
                                                ) : (
                                                    <Button
                                                        variant="subtle"
                                                        size="compact-sm"
                                                        loading={updateOperator.isPending}
                                                        onClick={() => setStatus(operator, 'ACTIVE')}
                                                    >
                                                        {t`Reassign`}
                                                    </Button>
                                                )}
                                            </Group>
                                        </Table.Td>
                                    </Table.Tr>
                                ))}
                            </Table.Tbody>
                        </Table>
                    </Table.ScrollContainer>
                </Card>
            )}

            {inviteOpen && <InviteBoxOfficeOperatorModal onClose={closeInvite}/>}
        </PageBody>
    );
};

export default BoxOfficeOperators;
