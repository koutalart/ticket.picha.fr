import {t} from "@lingui/macro";
import {Button, Group, Stack, Text, Textarea} from "@mantine/core";
import {useForm} from "@mantine/form";
import {IdParam, SocialPost} from "../../../../types.ts";
import {useRequestSocialPostChanges} from "../../../../mutations/useRequestSocialPostChanges.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {Modal} from "../../../common/Modal";

const COMMENT_MIN_LENGTH = 3;
const COMMENT_MAX_LENGTH = 1000;

interface RequestChangesModalProps {
    organizerId: IdParam;
    post: SocialPost;
    onClose: () => void;
}

export const RequestChangesModal = ({organizerId, post, onClose}: RequestChangesModalProps) => {
    const mutation = useRequestSocialPostChanges();
    const formErrorHandle = useFormErrorResponseHandler();
    const form = useForm({
        initialValues: {comment: ''},
        validate: {
            comment: (value: string) => {
                const length = value.trim().length;

                if (length < COMMENT_MIN_LENGTH) {
                    return t`Describe the change in a few words.`;
                }

                return length > COMMENT_MAX_LENGTH ? t`${COMMENT_MAX_LENGTH} characters maximum.` : null;
            },
        },
    });

    const submit = (values: { comment: string }) => {
        mutation.mutate({organizerId, postId: post.id, comment: values.comment.trim()}, {
            onSuccess: () => {
                showSuccess(t`Change request sent`);
                onClose();
            },
            onError: (error: any) => {
                if (error?.response?.status === 409) {
                    showError(error.response.data?.message ?? t`Unable to send the change request`);
                    return;
                }

                formErrorHandle(form, error);
            },
        });
    };

    return (
        <Modal opened onClose={onClose} heading={t`Request a change`}>
            <form onSubmit={form.onSubmit(submit)}>
                <Stack>
                    <Text size="sm" c="dimmed">{t`Say what should change (text, image, time). A new version will be proposed to you.`}</Text>
                    <Textarea
                        {...form.getInputProps('comment')}
                        label={t`Your comment`}
                        autosize
                        minRows={3}
                        maxLength={COMMENT_MAX_LENGTH}
                        required
                        data-autofocus
                    />
                    <Group justify="flex-end">
                        <Button variant="default" onClick={onClose}>{t`Cancel`}</Button>
                        <Button type="submit" loading={mutation.isPending}>{t`Send the request`}</Button>
                    </Group>
                </Stack>
            </form>
        </Modal>
    );
};
