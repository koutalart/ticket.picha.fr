import {t} from "@lingui/macro";
import {Button, Stack, Switch, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {Modal} from "../../../../common/Modal";
import {Contact, UpdateContactRequest} from "../../../../../api/contact.client.ts";
import {useUpdateContact} from "../../../../../mutations/useUpdateContact.ts";
import {useFormErrorResponseHandler} from "../../../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../../../utilites/notifications.tsx";
import {IdParam} from "../../../../../types.ts";

interface EditContactModalProps {
    organizerId: IdParam;
    contact: Contact;
    onClose: () => void;
}

export const EditContactModal = ({organizerId, contact, onClose}: EditContactModalProps) => {
    const mutation = useUpdateContact();
    const errorHandler = useFormErrorResponseHandler();
    const form = useForm<UpdateContactRequest>({
        initialValues: {
            first_name: contact.first_name ?? '',
            last_name: contact.last_name ?? '',
            phone: contact.phone ?? '',
            email_consent: contact.email_consent,
            sms_consent: contact.sms_consent,
        },
    });

    const handleSubmit = (values: UpdateContactRequest) => {
        mutation.mutate({organizerId, contactId: contact.id, data: values}, {
            onSuccess: () => {
                showSuccess(t`Contact updated`);
                onClose();
            },
            onError: (error) => errorHandler(form, error),
        });
    };

    return (
        <Modal opened onClose={onClose} heading={t`Edit contact`}>
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <Stack>
                    <TextInput label={t`First name`} {...form.getInputProps('first_name')}/>
                    <TextInput label={t`Last name`} {...form.getInputProps('last_name')}/>
                    <TextInput
                        label={t`Phone`}
                        description={t`Example: 0639 12 34 56 or +262 639 12 34 56`}
                        {...form.getInputProps('phone')}
                    />
                    <Switch
                        label={t`Accepts to receive your emails`}
                        description={t`Only tick this if the person agreed to it.`}
                        {...form.getInputProps('email_consent', {type: 'checkbox'})}
                    />
                    <Switch
                        label={t`Accepts to receive your SMS`}
                        description={t`Only tick this if the person agreed to it. A phone number is required.`}
                        {...form.getInputProps('sms_consent', {type: 'checkbox'})}
                    />
                    <Button type="submit" loading={mutation.isPending}>{t`Save`}</Button>
                </Stack>
            </form>
        </Modal>
    );
};
