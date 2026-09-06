import {useForm} from "@mantine/form";
import {GenericModalProps} from "../../../types.ts";
import {Modal} from "../../common/Modal";
import {Button, SimpleGrid, Text, TextInput} from "@mantine/core";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {t, Trans} from "@lingui/macro";
import {useParams} from "react-router";
import {showSuccess} from "../../../utilites/notifications.tsx";
import {useCreateBoxOfficeOperator} from "../../../mutations/useCreateBoxOfficeOperator.ts";
import {CreateBoxOfficeOperatorRequest} from "../../../api/box-office.client.ts";

export const InviteBoxOfficeOperatorModal = ({onClose}: GenericModalProps) => {
    const {eventId} = useParams();
    const createMutation = useCreateBoxOfficeOperator();
    const formErrorHandler = useFormErrorResponseHandler();

    const form = useForm<CreateBoxOfficeOperatorRequest>({
        initialValues: {
            email: '',
            first_name: '',
            last_name: '',
        },
    });

    const handleCreate = (values: CreateBoxOfficeOperatorRequest) => {
        createMutation.mutate({
            eventId: eventId!,
            operator: values,
        }, {
            onSuccess: () => {
                form.reset();
                onClose();
                showSuccess(
                    <Trans>Success! {values.first_name} can sign in on the kiosk after setting a password.</Trans>
                );
            },
            onError: (error: any) => formErrorHandler(form, error),
        });
    };

    return (
        <Modal heading={t`Invite a kiosk operator`} onClose={onClose} opened modalHeader={'branded'}>
            <Text mb="md" size="sm" c="dimmed">
                {t`They will receive an email to set a password, then sign in at the kiosk. They cannot access this back office.`}
            </Text>
            <form onSubmit={form.onSubmit((values) => handleCreate(values))}>
                <SimpleGrid cols={2}>
                    <TextInput required {...form.getInputProps('first_name')} label={t`First Name`}/>
                    <TextInput {...form.getInputProps('last_name')} label={t`Last Name`}/>
                </SimpleGrid>

                <TextInput required type={'email'} {...form.getInputProps('email')} label={t`Email`}/>

                <Button
                    fullWidth
                    loading={createMutation.isPending}
                    type={'submit'}
                >
                    {t`Invite Operator`}
                </Button>
            </form>
        </Modal>
    );
};
