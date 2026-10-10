import {t} from "@lingui/macro";
import {Button, Group, Stack, Switch, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {Modal} from "../../../common/Modal";
import {CreateContactRequest} from "../../../../api/contact.client.ts";
import {useCreateContact} from "../../../../mutations/useContactImport.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../../utilites/notifications.tsx";
import {IdParam} from "../../../../types.ts";

interface CreateContactModalProps {
    organizerId: IdParam;
    onClose: () => void;
}

export const CreateContactModal = ({organizerId, onClose}: CreateContactModalProps) => {
    const mutation = useCreateContact();
    const errorHandler = useFormErrorResponseHandler();
    const form = useForm<CreateContactRequest>({
        initialValues: {first_name: '', last_name: '', email: '', phone: '', email_consent: false, sms_consent: false},
    });

    const handleSubmit = (values: CreateContactRequest) => {
        mutation.mutate({
            organizerId,
            data: {...values, email: values.email?.trim() || null, phone: values.phone?.trim() || null},
        }, {
            onSuccess: () => {
                showSuccess(t`Contact created`);
                onClose();
            },
            onError: (error) => errorHandler(form, error),
        });
    };

    return (
        <Modal opened onClose={onClose} heading={t`Create a contact`}>
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <Stack>
                    <Group grow>
                        <TextInput label={t`First name`} {...form.getInputProps('first_name')}/>
                        <TextInput label={t`Last name`} {...form.getInputProps('last_name')}/>
                    </Group>
                    <TextInput label={t`Email`} type="email" {...form.getInputProps('email')}/>
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
                    <Button type="submit" loading={mutation.isPending}>{t`Create the contact`}</Button>
                </Stack>
            </form>
        </Modal>
    );
};
