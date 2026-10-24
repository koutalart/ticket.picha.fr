import {useState} from "react";
import {NavLink, useParams} from "react-router";
import {t} from "@lingui/macro";
import {Alert, Anchor, Badge, Button, Group, Pagination, Select, Skeleton, Text} from "@mantine/core";
import {IconAlertTriangle, IconBrandFacebook, IconBrandInstagram, IconCheck, IconExternalLink, IconPlus, IconTrash, IconX} from "@tabler/icons-react";
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
import {Card} from "../../../common/Card";
import {PostFormModal} from "./PostFormModal.tsx";
import {postFormatLabel, postStatusColor, postStatusLabel} from "./labels.ts";
import classes from "./Publications.module.scss";

const ALL = 'ALL';
const APPROVABLE: SocialPostStatus[] = ['DRAFT', 'PENDING_APPROVAL', 'FAILED', 'CANCELLED'];
const CANCELLABLE: SocialPostStatus[] = ['DRAFT', 'PENDING_APPROVAL', 'SCHEDULED'];
const DELETABLE: SocialPostStatus[] = ['DRAFT', 'PENDING_APPROVAL', 'FAILED', 'CANCELLED'];
const STATUSES: SocialPostStatus[] = ['PENDING_APPROVAL', 'SCHEDULED', 'PUBLISHED', 'FAILED', 'DRAFT', 'CANCELLED'];

const Publications = () => {
    const {organizerId} = useParams();
    const [status, setStatus] = useState<string>(ALL);
    const [page, setPage] = useState(1);
    const [creating, setCreating] = useState(false);
    const {data: organizer} = useGetOrganizer(organizerId);
    const connectionQuery = useGetSocialConnection(organizerId);
    const postsQuery = useGetSocialPosts(organizerId, {status: status === ALL ? undefined : status as SocialPostStatus, page});
    const scheduleMutation = useScheduleSocialPost();
    const cancelMutation = useCancelSocialPost();
    const deleteMutation = useDeleteSocialPost();
    const connection = connectionQuery.data;
    const canPublish = !!connection?.connected && connection.status === 'ACTIVE';
    const timezone = organizer?.timezone || 'Indian/Mayotte';
    const posts = postsQuery.data?.data ?? [];
    const meta = postsQuery.data?.meta;

    const approve = (post: SocialPost) => {
        const when = post.scheduled_at && new Date(post.scheduled_at) > new Date()
            ? t`It will be published on ${prettyDate(post.scheduled_at, timezone)}.`
            : t`It will be published within a minute.`;

        confirmationDialog(t`Approve this post? ${when}`, () => {
            scheduleMutation.mutate({organizerId: organizerId!, postId: post.id}, {
                onSuccess: () => showSuccess(t`Post approved`),
                onError: (error: any) => showError(error?.response?.data?.message ?? t`Unable to approve the post`),
            });
        });
    };

    const cancel = (post: SocialPost) => {
        cancelMutation.mutate({organizerId: organizerId!, postId: post.id}, {
            onSuccess: () => showSuccess(t`Post cancelled`),
            onError: (error: any) => showError(error?.response?.data?.message ?? t`Unable to cancel the post`),
        });
    };

    const remove = (post: SocialPost) => {
        confirmationDialog(t`Delete this post from the log?`, () => {
            deleteMutation.mutate({organizerId: organizerId!, postId: post.id}, {
                onSuccess: () => showSuccess(t`Post deleted`),
                onError: (error: any) => showError(error?.response?.data?.message ?? t`Unable to delete the post`),
            });
        });
    };

    const timeLabel = (post: SocialPost): string => {
        if (post.published_at) {
            return t`Published on ${prettyDate(post.published_at, timezone)}`;
        }
        if (post.scheduled_at) {
            return t`Planned for ${prettyDate(post.scheduled_at, timezone)}`;
        }
        return t`As soon as approved`;
    };

    const renderPost = (post: SocialPost) => (
        <Card key={post.id} style={{marginBottom: 0}}>
            <div className={classes.post}>
                <div style={{flex: 1, minWidth: 0}}>
                    <Group gap={6} mb={6}>
                        {post.network === 'INSTAGRAM' ? <IconBrandInstagram size={18}/> : <IconBrandFacebook size={18}/>}
                        <Badge variant="light" color="gray">{postFormatLabel(post.format)}</Badge>
                        <Badge variant="light" color={postStatusColor(post.status)}>{postStatusLabel(post.status)}</Badge>
                        {post.source === 'ASSISTANT' && <Badge variant="outline" color="violet">{t`Prepared by the assistant`}</Badge>}
                    </Group>
                    <Text size="xs" c="dimmed" mb={6}>{timeLabel(post)}</Text>
                    {post.caption && <Text size="sm" lineClamp={4} className={classes.caption}>{post.caption}</Text>}
                    {post.media.length > 0 && (
                        <Group gap="xs" mt={6}>
                            {post.media.map((item, index) => (
                                <Anchor key={index} href={item.url} target="_blank" rel="noopener noreferrer" size="xs">
                                    {item.type === 'VIDEO' ? t`Video ${index + 1}` : t`Image ${index + 1}`}
                                </Anchor>
                            ))}
                        </Group>
                    )}
                    {post.link && <Anchor href={post.link} target="_blank" rel="noopener noreferrer" size="xs">{post.link}</Anchor>}
                    {post.last_error && post.status !== 'PUBLISHED' && (
                        <Text size="xs" c={post.status === 'FAILED' ? 'red' : 'orange'} mt={6}>{post.last_error}</Text>
                    )}
                </div>
                <div className={classes.actions}>
                    {post.permalink && (
                        <Button component="a" href={post.permalink} target="_blank" rel="noopener noreferrer" size="xs" variant="light" leftSection={<IconExternalLink size={14}/>}>
                            {t`View`}
                        </Button>
                    )}
                    {APPROVABLE.includes(post.status) && (
                        <Button
                            size="xs"
                            leftSection={<IconCheck size={14}/>}
                            disabled={!canPublish}
                            loading={scheduleMutation.isPending && scheduleMutation.variables?.postId === post.id}
                            onClick={() => approve(post)}
                        >
                            {post.status === 'FAILED' ? t`Retry` : t`Approve`}
                        </Button>
                    )}
                    {CANCELLABLE.includes(post.status) && (
                        <Button
                            size="xs"
                            variant="default"
                            leftSection={<IconX size={14}/>}
                            loading={cancelMutation.isPending && cancelMutation.variables?.postId === post.id}
                            onClick={() => cancel(post)}
                        >
                            {t`Cancel`}
                        </Button>
                    )}
                    {DELETABLE.includes(post.status) && (
                        <Button size="xs" variant="subtle" color="red" leftSection={<IconTrash size={14}/>} onClick={() => remove(post)}>
                            {t`Delete`}
                        </Button>
                    )}
                </div>
            </div>
        </Card>
    );

    return (
        <PageBody>
            <PageTitle>{t`Publications`}</PageTitle>

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

            <div className={classes.toolbar}>
                <Select
                    w={220}
                    value={status}
                    allowDeselect={false}
                    onChange={(value) => {
                        setStatus(value ?? ALL);
                        setPage(1);
                    }}
                    data={[{value: ALL, label: t`All posts`}, ...STATUSES.map(value => ({value, label: postStatusLabel(value)}))]}
                    aria-label={t`Status`}
                />
                <Button leftSection={<IconPlus size={16}/>} disabled={!canPublish} onClick={() => setCreating(true)}>
                    {t`New post`}
                </Button>
            </div>

            {postsQuery.isLoading && <Skeleton height={120}/>}

            {postsQuery.isFetched && posts.length === 0 && (
                <Text c="dimmed" size="sm">{t`No post yet.`}</Text>
            )}

            <div className={classes.list}>{posts.map(renderPost)}</div>

            {meta && meta.last_page > 1 && (
                <Group justify="center" mt="md">
                    <Pagination size="sm" value={page} onChange={setPage} total={meta.last_page}/>
                </Group>
            )}

            {creating && connection && (
                <PostFormModal organizerId={organizerId!} connection={connection} timezone={timezone} onClose={() => setCreating(false)}/>
            )}
        </PageBody>
    );
};

export default Publications;
