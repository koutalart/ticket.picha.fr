import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {useEffect} from "react";
import {t} from "@lingui/macro";
import {Button, TextInput} from "@mantine/core";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {OrganizerSettings} from "../../../../../../types.ts";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {useGetOrganizerSettings} from "../../../../../../queries/useGetOrganizerSettings.ts";
import {useUpdateOrganizerSettings} from "../../../../../../mutations/useUpdateOrganizerSettings.ts";

const EMAIL_SENDER_NAME_MAX_LENGTH = 70;

export const EmailSenderSettings = () => {
    const {organizerId} = useParams();
    const organizerSettingsQuery = useGetOrganizerSettings(organizerId);
    const updateMutation = useUpdateOrganizerSettings();
    const formErrorHandle = useFormErrorResponseHandler();
    const form = useForm({
        initialValues: {
            email_sender_name: '',
        },
        validate: {
            email_sender_name: (value) => (/[\u0000-\u001F\u007F<>]/.test(value ?? '')
                ? t`The sender name cannot contain line breaks or angle brackets.`
                : null),
        },
    });

    useEffect(() => {
        if (organizerSettingsQuery?.isFetched && organizerSettingsQuery?.data) {
            form.setValues({
                email_sender_name: organizerSettingsQuery.data.email_sender_name ?? '',
            });
        }
    }, [organizerSettingsQuery.isFetched]);

    const handleSubmit = (values: Pick<OrganizerSettings, 'email_sender_name'>) => {
        updateMutation.mutate({
            organizerSettings: {
                email_sender_name: values.email_sender_name?.trim() || null,
            },
            organizerId: organizerId,
        }, {
            onSuccess: () => {
                showSuccess(t`Email sender name updated`);
            },
            onError: (error) => {
                formErrorHandle(form, error);
            }
        });
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Email sender`}
                description={t`Choose the name your attendees see when they receive your emails.`}
            />
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={organizerSettingsQuery.isLoading || updateMutation.isPending}>
                    <TextInput
                        {...form.getInputProps('email_sender_name')}
                        label={t`Email sender name`}
                        description={t`Name shown as the sender of emails sent to your attendees. The sending address stays the platform one. Leave empty for PICHA Ticket.`}
                        placeholder="PICHA Ticket"
                        maxLength={EMAIL_SENDER_NAME_MAX_LENGTH}
                    />
                    <Button loading={updateMutation.isPending} type={'submit'}>
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
};
