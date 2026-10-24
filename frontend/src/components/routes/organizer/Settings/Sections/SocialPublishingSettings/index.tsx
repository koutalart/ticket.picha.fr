import {useEffect} from "react";
import {NavLink, useParams, useSearchParams} from "react-router";
import {t} from "@lingui/macro";
import {Alert, Badge, Button, Group, Paper, Skeleton, Stack, Text} from "@mantine/core";
import {IconAlertTriangle, IconBrandFacebook, IconBrandInstagram, IconPlugConnected, IconPlugConnectedX, IconSpeakerphone} from "@tabler/icons-react";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {MetaPage} from "../../../../../../types.ts";
import {useGetSocialConnection} from "../../../../../../queries/useGetSocialConnection.ts";
import {useGetPendingMetaPages} from "../../../../../../queries/useGetPendingMetaPages.ts";
import {useGetMetaLoginUrl} from "../../../../../../mutations/useGetMetaLoginUrl.ts";
import {useConnectMetaPage} from "../../../../../../mutations/useConnectMetaPage.ts";
import {useDisconnectSocialConnection} from "../../../../../../mutations/useDisconnectSocialConnection.ts";
import {showError, showSuccess} from "../../../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../../../utilites/confirmationDialog.tsx";
import {relativeDate} from "../../../../../../utilites/dates.ts";

const SELECTION_PARAM = 'social_selection';
const ERROR_PARAM = 'social_error';

const callbackErrorMessage = (error: string): string => {
    switch (error) {
        case 'cancelled':
            return t`The Facebook connection was cancelled. Nothing was changed.`;
        case 'expired':
            return t`The Facebook connection link has expired. Please try again.`;
        default:
            return t`Facebook did not accept the connection. Please try again, or check that you manage the page.`;
    }
};

export const SocialPublishingSettings = () => {
    const {organizerId} = useParams();
    const [searchParams, setSearchParams] = useSearchParams();
    const selectionKey = searchParams.get(SELECTION_PARAM);
    const callbackError = searchParams.get(ERROR_PARAM);
    const connectionQuery = useGetSocialConnection(organizerId);
    const pagesQuery = useGetPendingMetaPages(organizerId, selectionKey);
    const loginUrlMutation = useGetMetaLoginUrl();
    const connectMutation = useConnectMetaPage();
    const disconnectMutation = useDisconnectSocialConnection();
    const connection = connectionQuery.data;

    useEffect(() => {
        if (callbackError) {
            showError(callbackErrorMessage(callbackError));
            clearCallbackParams();
        }
    }, [callbackError]);

    const clearCallbackParams = () => {
        const next = new URLSearchParams(searchParams);
        next.delete(SELECTION_PARAM);
        next.delete(ERROR_PARAM);
        setSearchParams(next, {replace: true, preventScrollReset: true});
    };

    const startConnection = () => {
        loginUrlMutation.mutate({organizerId: organizerId!}, {
            onSuccess: (url) => {
                window.location.href = url;
            },
            onError: () => showError(t`Unable to open the Facebook connection. Please try again.`),
        });
    };

    const choosePage = (page: MetaPage) => {
        connectMutation.mutate({organizerId: organizerId!, selectionKey: selectionKey!, pageId: page.id}, {
            onSuccess: () => {
                showSuccess(t`The page ${page.name} is connected`);
                clearCallbackParams();
            },
            onError: (error: any) => showError(error?.response?.data?.errors?.page_id?.[0] ?? error?.response?.data?.message ?? t`Unable to connect this page`),
        });
    };

    const disconnect = () => {
        confirmationDialog(t`Disconnect the Facebook page? Scheduled posts will not be published until a page is connected again.`, () => {
            disconnectMutation.mutate({organizerId: organizerId!}, {
                onSuccess: () => showSuccess(t`Facebook page disconnected`),
                onError: () => showError(t`Unable to disconnect the page`),
            });
        });
    };

    const renderPageChoice = () => {
        if (pagesQuery.isLoading) {
            return <Skeleton height={80}/>;
        }

        if (pagesQuery.isError) {
            return (
                <Alert color="orange" icon={<IconAlertTriangle size={16}/>}>
                    {t`The page choice has expired. Connect again to choose your page.`}
                </Alert>
            );
        }

        const pages = pagesQuery.data ?? [];

        if (pages.length === 0) {
            return (
                <Alert color="orange" icon={<IconAlertTriangle size={16}/>}>
                    {t`No Facebook page was shared with PICHA. Connect again and select your page in the Facebook window.`}
                </Alert>
            );
        }

        return (
            <Stack gap="xs">
                <Text size="sm" fw={500}>{t`Choose the page to connect`}</Text>
                {pages.map(page => (
                    <Paper key={page.id} withBorder p="sm" radius="md">
                        <Group justify="space-between" wrap="nowrap">
                            <div>
                                <Group gap={6}>
                                    <IconBrandFacebook size={16}/>
                                    <Text size="sm" fw={500}>{page.name}</Text>
                                </Group>
                                <Group gap={6} mt={4}>
                                    <IconBrandInstagram size={16}/>
                                    <Text size="xs" c="dimmed">
                                        {page.instagram_username ? `@${page.instagram_username}` : t`No Instagram professional account linked to this page`}
                                    </Text>
                                </Group>
                                {!page.can_publish && (
                                    <Text size="xs" c="red" mt={4}>{t`Your Facebook account cannot publish on this page.`}</Text>
                                )}
                            </div>
                            <Button
                                size="xs"
                                disabled={!page.can_publish}
                                loading={connectMutation.isPending && connectMutation.variables?.pageId === page.id}
                                onClick={() => choosePage(page)}
                            >
                                {t`Connect`}
                            </Button>
                        </Group>
                    </Paper>
                ))}
                <Group>
                    <Button variant="subtle" size="xs" onClick={clearCallbackParams}>{t`Cancel`}</Button>
                </Group>
            </Stack>
        );
    };

    const renderConnection = () => {
        if (!connection?.connected) {
            return (
                <Group>
                    <Button
                        leftSection={<IconPlugConnected size={16}/>}
                        onClick={startConnection}
                        loading={loginUrlMutation.isPending}
                    >
                        {t`Connect a Facebook page`}
                    </Button>
                </Group>
            );
        }

        const needsReconnect = connection.status === 'NEEDS_RECONNECT';

        return (
            <Stack gap="sm">
                {needsReconnect && (
                    <Alert color="red" icon={<IconAlertTriangle size={16}/>} title={t`The connection must be renewed`}>
                        <Text size="sm">{t`Facebook no longer accepts PICHA's access. Connect the page again to resume publishing.`}</Text>
                        {connection.last_error && <Text size="xs" c="dimmed" mt={4}>{connection.last_error}</Text>}
                    </Alert>
                )}
                <Paper withBorder p="sm" radius="md">
                    <Group justify="space-between" wrap="nowrap" align="flex-start">
                        <Stack gap={4}>
                            <Group gap={6}>
                                <IconBrandFacebook size={16}/>
                                <Text size="sm" fw={500}>{connection.facebook_page_name}</Text>
                                <Badge size="sm" variant="light" color={needsReconnect ? 'red' : 'green'}>
                                    {needsReconnect ? t`To renew` : t`Connected`}
                                </Badge>
                            </Group>
                            <Group gap={6}>
                                <IconBrandInstagram size={16}/>
                                <Text size="sm" c={connection.instagram_username ? undefined : 'dimmed'}>
                                    {connection.instagram_username ? `@${connection.instagram_username}` : t`No Instagram professional account linked to this page`}
                                </Text>
                            </Group>
                            {connection.connected_at && (
                                <Text size="xs" c="dimmed">{t`Connected ${relativeDate(connection.connected_at)}`}</Text>
                            )}
                        </Stack>
                    </Group>
                </Paper>
                <Group>
                    <Button
                        component={NavLink}
                        to={`/manage/organizer/${organizerId}/publications`}
                        variant="light"
                        leftSection={<IconSpeakerphone size={16}/>}
                    >
                        {t`Open publications`}
                    </Button>
                    <Button variant="default" onClick={startConnection} loading={loginUrlMutation.isPending}>
                        {needsReconnect ? t`Reconnect` : t`Change page`}
                    </Button>
                    <Button
                        variant="subtle"
                        color="red"
                        leftSection={<IconPlugConnectedX size={16}/>}
                        onClick={disconnect}
                        loading={disconnectMutation.isPending}
                    >
                        {t`Disconnect`}
                    </Button>
                </Group>
            </Stack>
        );
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Facebook and Instagram`}
                description={t`Connect your Facebook page and the Instagram professional account linked to it to schedule your posts from PICHA. Every post is checked by a person before it goes out.`}
            />

            {connectionQuery.isLoading && <Skeleton height={60}/>}

            {connection && !connection.available && (
                <Alert color="gray" icon={<IconAlertTriangle size={16}/>}>
                    {t`Publishing on Facebook and Instagram is not yet available on this server.`}
                </Alert>
            )}

            {connection?.available && (selectionKey ? renderPageChoice() : renderConnection())}
        </Card>
    );
};
