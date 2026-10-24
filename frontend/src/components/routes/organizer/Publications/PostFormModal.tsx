import {t} from "@lingui/macro";
import {ActionIcon, Alert, Button, Checkbox, Group, SegmentedControl, Select, Stack, Switch, Text, Textarea, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {IconInfoCircle, IconPlus, IconTrash} from "@tabler/icons-react";
import dayjs from "dayjs";
import {IdParam, SocialConnection, SocialMediaType, SocialNetwork, SocialPostFormat} from "../../../../types.ts";
import {useCreateSocialPosts} from "../../../../mutations/useCreateSocialPosts.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../../utilites/notifications.tsx";
import {Modal} from "../../../common/Modal";
import {DateTimeInput} from "../../../common/DateTimeInput";
import {tzToUtc} from "../../../../utilites/dates.ts";
import {postFormatLabel} from "./labels.ts";
import classes from "./Publications.module.scss";

const INSTAGRAM_CAPTION_MAX_LENGTH = 2200;
const FORMATS: SocialPostFormat[] = ['IMAGE', 'CAROUSEL', 'REEL', 'STORY', 'TEXT'];

interface PostFormModalProps {
    organizerId: IdParam;
    connection: SocialConnection;
    timezone: string;
    onClose: () => void;
}

interface MediaValue {
    url: string;
    type: SocialMediaType;
}

interface FormValues {
    networks: SocialNetwork[];
    format: SocialPostFormat;
    caption: string;
    media: MediaValue[];
    link: string;
    schedule: boolean;
    scheduled_at: string;
}

const defaultMediaType = (format: SocialPostFormat): SocialMediaType => format === 'REEL' ? 'VIDEO' : 'IMAGE';

export const PostFormModal = ({organizerId, connection, timezone, onClose}: PostFormModalProps) => {
    const createMutation = useCreateSocialPosts();
    const formErrorHandle = useFormErrorResponseHandler();
    const hasInstagram = !!connection.instagram_account_id;

    const form = useForm<FormValues>({
        initialValues: {
            networks: hasInstagram ? ['INSTAGRAM', 'FACEBOOK'] : ['FACEBOOK'],
            format: 'IMAGE',
            caption: '',
            media: [{url: '', type: 'IMAGE'}],
            link: '',
            schedule: false,
            scheduled_at: '',
        },
        validate: {
            networks: (value) => value.length === 0 ? t`Choose at least one network` : null,
            scheduled_at: (value, values) => values.schedule && !value ? t`Choose the date and time of publication` : null,
        },
    });

    const {format, networks, media} = form.values;
    const isText = format === 'TEXT';
    const allowsSeveralMedia = format === 'CAROUSEL';
    const mediaTypeLocked = format === 'IMAGE' || format === 'CAROUSEL' || format === 'REEL';

    const changeFormat = (value: string) => {
        const next = value as SocialPostFormat;
        form.setFieldValue('format', next);
        form.setFieldValue('media', next === 'TEXT' ? [] : [{url: '', type: defaultMediaType(next)}, ...(next === 'CAROUSEL' ? [{url: '', type: 'IMAGE' as SocialMediaType}] : [])]);
        if (next === 'TEXT') {
            form.setFieldValue('networks', ['FACEBOOK']);
        }
    };

    const submit = (values: FormValues, asDraft: boolean) => {
        createMutation.mutate({
            organizerId,
            post: {
                networks: values.networks,
                format: values.format,
                caption: values.caption.trim() || null,
                media: values.media.filter(item => item.url.trim() !== '').map(item => ({url: item.url.trim(), type: item.type})),
                link: values.link.trim() || null,
                scheduled_at: values.schedule && values.scheduled_at ? tzToUtc(values.scheduled_at, timezone) : null,
                as_draft: asDraft,
            },
        }, {
            onSuccess: () => {
                showSuccess(asDraft ? t`Draft saved` : t`Post scheduled`);
                onClose();
            },
            onError: (error) => formErrorHandle(form, error),
        });
    };

    const validateThenSubmit = (asDraft: boolean) => {
        if (!form.validate().hasErrors) {
            submit(form.values, asDraft);
        }
    };

    return (
        <Modal opened onClose={onClose} heading={t`New post`}>
            <form onSubmit={(event) => {
                event.preventDefault();
                validateThenSubmit(false);
            }}>
                <Stack>
                    <Checkbox.Group label={t`Networks`} {...form.getInputProps('networks')}>
                        <Group mt={6}>
                            <Checkbox value="INSTAGRAM" label="Instagram" disabled={!hasInstagram || isText}/>
                            <Checkbox value="FACEBOOK" label="Facebook"/>
                        </Group>
                    </Checkbox.Group>
                    {!hasInstagram && (
                        <Text size="xs" c="dimmed">{t`No Instagram professional account is linked to the connected Facebook page.`}</Text>
                    )}

                    <div>
                        <Text size="sm" fw={500} mb={4}>{t`Format`}</Text>
                        <SegmentedControl
                            fullWidth
                            value={format}
                            onChange={changeFormat}
                            data={FORMATS.map(value => ({value, label: postFormatLabel(value)}))}
                        />
                    </div>

                    {format !== 'STORY' && (
                        <Textarea
                            {...form.getInputProps('caption')}
                            label={t`Text`}
                            autosize
                            minRows={4}
                            maxRows={12}
                            description={networks.includes('INSTAGRAM')
                                ? t`${form.values.caption.length} / ${INSTAGRAM_CAPTION_MAX_LENGTH} characters on Instagram`
                                : undefined}
                            error={form.errors.caption}
                        />
                    )}

                    {!isText && (
                        <Stack gap="xs">
                            <Text size="sm" fw={500}>{allowsSeveralMedia ? t`Images (2 to 10)` : t`Media`}</Text>
                            {media.map((_, index) => (
                                <div key={index} className={classes.mediaRow}>
                                    <TextInput
                                        {...form.getInputProps(`media.${index}.url`)}
                                        placeholder="https://"
                                        aria-label={t`Public link of the media`}
                                        style={{flex: 1}}
                                    />
                                    {!mediaTypeLocked && (
                                        <Select
                                            w={120}
                                            data={[{value: 'IMAGE', label: t`Image`}, {value: 'VIDEO', label: t`Video`}]}
                                            allowDeselect={false}
                                            {...form.getInputProps(`media.${index}.type`)}
                                        />
                                    )}
                                    {allowsSeveralMedia && media.length > 2 && (
                                        <ActionIcon variant="subtle" color="red" mt={6} onClick={() => form.removeListItem('media', index)} aria-label={t`Remove`}>
                                            <IconTrash size={16}/>
                                        </ActionIcon>
                                    )}
                                </div>
                            ))}
                            {allowsSeveralMedia && media.length < 10 && (
                                <Group>
                                    <Button variant="subtle" size="xs" leftSection={<IconPlus size={14}/>} onClick={() => form.insertListItem('media', {url: '', type: 'IMAGE'})}>
                                        {t`Add an image`}
                                    </Button>
                                </Group>
                            )}
                            <Alert color="blue" variant="light" icon={<IconInfoCircle size={16}/>} p="xs">
                                <Text size="xs">
                                    {t`Media must be public https:// links. Instagram only accepts JPEG images. Videos: vertical HD 1080x1920, H.264 and AAC.`}
                                </Text>
                            </Alert>
                        </Stack>
                    )}

                    {isText && (
                        <TextInput {...form.getInputProps('link')} label={t`Link`} placeholder="https://"/>
                    )}

                    <Switch {...form.getInputProps('schedule', {type: 'checkbox'})} label={t`Choose the publication time`}/>
                    {form.values.schedule && (
                        <DateTimeInput
                            {...form.getInputProps('scheduled_at')}
                            label={t`Publication time`}
                            description={t`Time zone: ${timezone}`}
                            minDate={dayjs().format('YYYY-MM-DD')}
                        />
                    )}

                    <Group justify="flex-end">
                        <Button variant="default" onClick={() => validateThenSubmit(true)} loading={createMutation.isPending && createMutation.variables?.post.as_draft}>
                            {t`Save as draft`}
                        </Button>
                        <Button type="submit" loading={createMutation.isPending && !createMutation.variables?.post.as_draft}>
                            {form.values.schedule ? t`Schedule` : t`Publish now`}
                        </Button>
                    </Group>
                </Stack>
            </form>
        </Modal>
    );
};
