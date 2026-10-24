import {useState} from "react";
import {NavLink, useParams} from "react-router";
import {t} from "@lingui/macro";
import {Alert, Anchor, Badge, Button, Group, Pagination, Skeleton, Tabs, Text} from "@mantine/core";
import {IconAlertTriangle, IconPlus} from "@tabler/icons-react";
import {SocialPost, SocialPostStatus} from "../../../../types.ts";
import {useGetSocialConnection} from "../../../../queries/useGetSocialConnection.ts";
import {useGetSocialPosts} from "../../../../queries/useGetSocialPosts.ts";
import {useGetOrganizer} from "../../../../queries/useGetOrganizer.ts";
import {useScheduleSocialPost} from "../../../../mutations/useScheduleSocialPost.ts";
import {useCancelSocialPost} from "../../../../mutations/useCancelSocialPost.ts";
import {useDeleteSocialPost} from "../../../../mutations/useDeleteSocialPost.ts";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../utilites/confirmationDialog.tsx";
import {prettyDate} from "../../../../utilites/dates.ts";
import {PageBody} from "../../../common/PageBody";
import {PageTitle} from "../../../common/PageTitle";
import {PostFormModal} from "./PostFormModal.tsx";
import {PostCard, PostActions} from "./PostCard.tsx";
import {PostViewer} from "./PostViewer.tsx";
import {postTabLabel} from "./labels.ts";
import classes from "./Publications.module.scss";

const ALL = 'ALL';
const TABS: SocialPostStatus[] = ['PENDING_APPROVAL', 'SCHEDULED', 'PUBLISHED', 'FAILED', 'DRAFT', 'CANCELLED'];

const Publications = () => {
    const {organizerId} = useParams();
    const [status, setStatus] = useState<string>(ALL);
    const [page, setPage] = useState(1);
    const [creating, setCreating] = useState(false);
    const [viewerIndex, setViewerIndex] = useState<number | null>(null);
    const {data: organizer} = useGetOrganizer(organizerId);
    const connectionQuery = useGetSocialConnection(organizerId);
    const postsQuery = useGetSocialPosts(organizerId, {status: status === ALL ? undefined : status as SocialPostStatus, page});
    const pendingQuery = useGetSocialPosts(organizerId, {status: 'PENDING_APPROVAL', page: 1});
    const scheduleMutation = useScheduleSocialPost();
    const cancelMutation = useCancelSocialPost();
    const deleteMutation = useDeleteSocialPost();
    const connection = connectionQuery.data;
    const canPublish = !!connection?.connected && connection.status === 'ACTIVE';
    const timezone = organizer?.timezone || 'Indian/Mayotte';
    const posts = postsQuery.data?.data ?? [];
    const meta = postsQuery.data?.meta;
    const pendingCount = pendingQuery.data?.meta?.total ?? 0;

    const actions: PostActions = {
        canPublish,
        pendingPostId: scheduleMutation.isPending ? Number(scheduleMutation.variables?.postId) : undefined,
        approve: (post: SocialPost) => {
            const when = post.scheduled_at && new Date(post.scheduled_at) > new Date()
                ? t`It will be published on ${prettyDate(post.scheduled_at, timezone)}.`
                : t`It will be published within a minute.`;

            confirmationDialog(t`Approve this post? ${when}`, () => {
                scheduleMutation.mutate({organizerId: organizerId!, postId: post.id}, {
                    onSuccess: () => showSuccess(t`Post approved`),
                    onError: (error: any) => showError(error?.response?.data?.message ?? t`Unable to approve the post`),
                });
            });
        },
        cancel: (post: SocialPost) => {
            cancelMutation.mutate({organizerId: organizerId!, postId: post.id}, {
                onSuccess: () => showSuccess(t`Post cancelled`),
                onError: (error: any) => showError(error?.response?.data?.message ?? t`Unable to cancel the post`),
            });
        },
        remove: (post: SocialPost) => {
            confirmationDialog(t`Delete this post from the log?`, () => {
                deleteMutation.mutate({organizerId: organizerId!, postId: post.id}, {
                    onSuccess: () => {
                        showSuccess(t`Post deleted`);
                        setViewerIndex(null);
                    },
                    onError: (error: any) => showError(error?.response?.data?.message ?? t`Unable to delete the post`),
                });
            });
        },
    };

    const changeTab = (value: string | null) => {
        setStatus(value ?? ALL);
        setPage(1);
    };

    return (
        <PageBody>
            <div className={classes.feedColumn}>
                <div className={classes.feedHeader}>
                    <PageTitle>{t`Publications`}</PageTitle>
                    <Button leftSection={<IconPlus size={16}/>} disabled={!canPublish} onClick={() => setCreating(true)}>
                        {t`New post`}
                    </Button>
                </div>

                {connectionQuery.isFetched && !canPublish && (
                    <Alert color="orange" icon={<IconAlertTriangle size={16}/>} mb="md">
                        {connection?.connected
                            ? t`The Facebook connection must be renewed before anything can be published.`
                            : t`Connect a Facebook page to publish on Facebook and Instagram.`}
                        {' '}
                        <Anchor component={NavLink} to={`/manage/organizer/${organizerId}/settings#social`} size="sm">
                            {t`Open the settings`}
                        </Anchor>
                    </Alert>
                )}

                <Tabs value={status} onChange={changeTab} variant="pills" radius="xl" mb="md">
                    <Tabs.List className={classes.tabs}>
                        <Tabs.Tab value={ALL}>{t`All`}</Tabs.Tab>
                        {TABS.map(value => (
                            <Tabs.Tab
                                key={value}
                                value={value}
                                rightSection={value === 'PENDING_APPROVAL' && pendingCount > 0
                                    ? <Badge size="xs" circle color="orange">{pendingCount}</Badge>
                                    : undefined}
                            >
                                {postTabLabel(value)}
                            </Tabs.Tab>
                        ))}
                    </Tabs.List>
                </Tabs>

                {postsQuery.isLoading && <Skeleton height={480} radius="md"/>}

                {postsQuery.isFetched && posts.length === 0 && (
                    <Text c="dimmed" size="sm" ta="center" py="xl">{t`No post yet.`}</Text>
                )}

                <div className={classes.feed}>
                    {posts.map((post, position) => (
                        <PostCard
                            key={post.id}
                            post={post}
                            connection={connection}
                            timezone={timezone}
                            actions={actions}
                            onOpen={() => setViewerIndex(position)}
                        />
                    ))}
                </div>

                {meta && meta.last_page > 1 && (
                    <Group justify="center" mt="md">
                        <Pagination size="sm" value={page} onChange={setPage} total={meta.last_page}/>
                    </Group>
                )}
            </div>

            {viewerIndex !== null && (
                <PostViewer
                    posts={posts}
                    index={viewerIndex}
                    connection={connection}
                    timezone={timezone}
                    actions={actions}
                    onChange={setViewerIndex}
                    onClose={() => setViewerIndex(null)}
                />
            )}

            {creating && connection && (
                <PostFormModal organizerId={organizerId!} connection={connection} timezone={timezone} onClose={() => setCreating(false)}/>
            )}
        </PageBody>
    );
};

export default Publications;
