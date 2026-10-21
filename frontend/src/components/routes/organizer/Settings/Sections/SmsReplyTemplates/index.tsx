import {useState} from "react";
import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {t} from "@lingui/macro";
import {ActionIcon, Button, Group, Stack, Text, Textarea, TextInput} from "@mantine/core";
import {IconPencil, IconPlus, IconTrash} from "@tabler/icons-react";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {Modal} from "../../../../../common/Modal";
import {SmsReplyTemplate} from "../../../../../../types.ts";
import {useGetSmsReplyTemplates} from "../../../../../../queries/useGetSmsReplyTemplates.ts";
import {useSaveSmsReplyTemplate} from "../../../../../../mutations/useSaveSmsReplyTemplate.ts";
import {useDeleteSmsReplyTemplate} from "../../../../../../mutations/useDeleteSmsReplyTemplate.ts";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {showError, showSuccess} from "../../../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../../../utilites/confirmationDialog.tsx";
import {countSmsSegments} from "../../../Sms/smsSegments.ts";
import {SMS_FIRST_NAME_VARIABLE} from "../../../Sms/replyTemplates.ts";

const MAX_LENGTH = 640;

export const SmsReplyTemplates = () => {
    const {organizerId} = useParams();
    const templatesQuery = useGetSmsReplyTemplates(organizerId);
    const saveMutation = useSaveSmsReplyTemplate();
    const deleteMutation = useDeleteSmsReplyTemplate();
    const formErrorHandle = useFormErrorResponseHandler();
    const [editing, setEditing] = useState<SmsReplyTemplate | 'new' | null>(null);
    const templates = templatesQuery.data ?? [];

    const form = useForm({
        initialValues: {name: '', content: ''},
    });

    const open = (template: SmsReplyTemplate | 'new') => {
        form.setValues(template === 'new' ? {name: '', content: ''} : {name: template.name, content: template.content});
        form.clearErrors();
        setEditing(template);
    };

    const save = (values: typeof form.values) => {
        saveMutation.mutate({
            organizerId: organizerId!,
            templateId: editing && editing !== 'new' ? editing.id : undefined,
            template: {name: values.name.trim(), content: values.content.trim()},
        }, {
            onSuccess: () => {
                setEditing(null);
                showSuccess(t`Reply template saved`);
            },
            onError: (error) => formErrorHandle(form, error),
        });
    };

    const remove = (template: SmsReplyTemplate) => {
        confirmationDialog(t`Delete the reply template "${template.name}"?`, () => {
            deleteMutation.mutate({organizerId: organizerId!, templateId: template.id}, {
                onSuccess: () => showSuccess(t`Reply template deleted`),
                onError: () => showError(t`Unable to delete the reply template`),
            });
        });
    };

    const segments = countSmsSegments(form.values.content);

    return (
        <Card>
            <HeadingWithDescription
                heading={t`SMS reply templates`}
                description={t`Save the answers you send often (opening hours, access, refunds) and insert them in one click when replying to an SMS. Write ${SMS_FIRST_NAME_VARIABLE} to insert the customer's first name.`}
            />

            <Stack gap="xs" mb="md">
                {templatesQuery.isFetched && templates.length === 0 && (
                    <Text size="sm" c="dimmed">{t`No reply template yet.`}</Text>
                )}
                {templates.map(template => (
                    <Group key={template.id} justify="space-between" wrap="nowrap" align="flex-start">
                        <div style={{minWidth: 0}}>
                            <Text size="sm" fw={600}>{template.name}</Text>
                            <Text size="xs" c="dimmed" lineClamp={2} style={{whiteSpace: 'pre-line'}}>{template.content}</Text>
                        </div>
                        <Group gap={4} wrap="nowrap">
                            <ActionIcon variant="subtle" onClick={() => open(template)} aria-label={t`Edit`}>
                                <IconPencil size={16}/>
                            </ActionIcon>
                            <ActionIcon variant="subtle" color="red" onClick={() => remove(template)} aria-label={t`Delete`}>
                                <IconTrash size={16}/>
                            </ActionIcon>
                        </Group>
                    </Group>
                ))}
            </Stack>

            <Button variant="light" leftSection={<IconPlus size={16}/>} onClick={() => open('new')}>
                {t`Add a reply template`}
            </Button>

            {editing && (
                <Modal
                    opened
                    onClose={() => setEditing(null)}
                    heading={editing === 'new' ? t`New reply template` : t`Edit reply template`}
                >
                    <form onSubmit={form.onSubmit(save)}>
                        <TextInput
                            {...form.getInputProps('name')}
                            label={t`Name`}
                            placeholder={t`Opening hours`}
                            maxLength={100}
                            required
                            mb="md"
                        />
                        <Textarea
                            {...form.getInputProps('content')}
                            label={t`Text`}
                            placeholder={t`Hello ${SMS_FIRST_NAME_VARIABLE}, doors open at 7pm.`}
                            description={t`${segments.characters} characters, ${segments.segments} SMS`}
                            autosize
                            minRows={3}
                            maxRows={10}
                            maxLength={MAX_LENGTH}
                            required
                            mb="md"
                        />
                        <Button type="submit" fullWidth loading={saveMutation.isPending}>{t`Save`}</Button>
                    </form>
                </Modal>
            )}
        </Card>
    );
};
