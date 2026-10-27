import {useState} from "react";
import {t} from "@lingui/macro";
import {Button, Select, Stack, Text, Textarea, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useDebouncedValue, useMediaQuery} from "@mantine/hooks";
import {IconSend} from "@tabler/icons-react";
import {EmailMessage, IdParam} from "../../../../../types.ts";
import {Modal} from "../../../../common/Modal";
import {useSearchEmailRecipients} from "../../../../../queries/useSearchEmailRecipients.ts";
import {useSendNewEmail} from "../../../../../mutations/useSendNewEmail.ts";
import {useFormErrorResponseHandler} from "../../../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../../../utilites/notifications.tsx";

interface NewEmailModalProps {
    organizerId: IdParam;
    opened: boolean;
    onClose: () => void;
    onSent: (message: EmailMessage) => void;
}

interface NewEmailForm {
    email: string;
    subject: string;
    body: string;
}

export const NewEmailModal = ({organizerId, opened, onClose, onSent}: NewEmailModalProps) => {
    const [search, setSearch] = useState('');
    const [debouncedSearch] = useDebouncedValue(search, 300);
    const isMobile = useMediaQuery('(max-width: 760px)');
    const recipientsQuery = useSearchEmailRecipients(organizerId, debouncedSearch, opened);
    const sendMutation = useSendNewEmail();
    const errorHandler = useFormErrorResponseHandler();
    const form = useForm<NewEmailForm>({
        initialValues: {email: '', subject: '', body: ''},
        validate: {
            email: (value) => value ? null : t`Choose a recipient among your contacts`,
            subject: (value) => value.trim() ? null : t`Add a subject`,
            body: (value) => value.trim() ? null : t`Write your message`,
        },
    });

    const recipients = recipientsQuery.data ?? [];
    const options = recipients.map(recipient => ({
        value: recipient.email,
        label: recipient.name ? `${recipient.name} (${recipient.email})` : recipient.email,
        disabled: recipient.unsubscribed,
    }));

    if (form.values.email && !options.some(option => option.value === form.values.email)) {
        options.unshift({value: form.values.email, label: form.values.email, disabled: false});
    }

    const close = () => {
        form.reset();
        setSearch('');
        onClose();
    };

    const submit = (values: NewEmailForm) => {
        sendMutation.mutate({organizerId, request: values}, {
            onSuccess: ({data}) => {
                showSuccess(t`E-mail sent`);
                form.reset();
                setSearch('');
                onSent(data);
            },
            onError: (error) => errorHandler(form, error),
        });
    };

    return (
        <Modal opened={opened} onClose={close} heading={t`New e-mail`} fullScreen={!!isMobile}>
            <form onSubmit={form.onSubmit(submit)}>
                <Stack gap="sm">
                    <Select
                        label={t`To`}
                        placeholder={t`Search a contact, buyer or attendee`}
                        searchable
                        searchValue={search}
                        onSearchChange={setSearch}
                        filter={({options: items}) => items}
                        data={options}
                        nothingFoundMessage={recipientsQuery.isFetching ? t`Searching...` : t`No contact found`}
                        description={t`Only your contacts, buyers and attendees can be chosen. Unsubscribed contacts are greyed out.`}
                        {...form.getInputProps('email')}
                    />
                    <TextInput
                        label={t`Subject`}
                        maxLength={200}
                        {...form.getInputProps('subject')}
                    />
                    <Textarea
                        label={t`Message`}
                        autosize
                        minRows={6}
                        maxRows={16}
                        maxLength={10000}
                        {...form.getInputProps('body')}
                    />
                    <Text size="xs" c="dimmed">
                        {t`Sent from the platform address with your organizer name. The answer will come back in Messages, E-mails.`}
                    </Text>
                    <Button type="submit" leftSection={<IconSend size={16}/>} loading={sendMutation.isPending}>
                        {t`Send`}
                    </Button>
                </Stack>
            </form>
        </Modal>
    );
};
