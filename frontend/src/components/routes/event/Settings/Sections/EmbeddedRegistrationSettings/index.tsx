import {t} from "@lingui/macro";
import {Alert, Button, Switch} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useParams} from "react-router";
import {useEffect} from "react";
import {IconInfoCircle} from "@tabler/icons-react";
import {Card} from "../../../../../common/Card";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {useUpdateEventSettings} from "../../../../../../mutations/useUpdateEventSettings.ts";
import {useGetEventSettings} from "../../../../../../queries/useGetEventSettings.ts";
import {useGetEvent} from "../../../../../../queries/useGetEvent.ts";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {isFreeEvent} from "../../../../../../utilites/embeddedRegistration.ts";

export const EmbeddedRegistrationSettings = () => {
    const {eventId} = useParams();
    const eventSettingsQuery = useGetEventSettings(eventId);
    const {data: event} = useGetEvent(eventId);
    const updateMutation = useUpdateEventSettings();
    const formErrorHandle = useFormErrorResponseHandler();
    const form = useForm({
        initialValues: {
            embedded_registration_enabled: false,
        }
    });

    useEffect(() => {
        if (eventSettingsQuery?.isFetched && eventSettingsQuery?.data) {
            form.setValues({
                embedded_registration_enabled: eventSettingsQuery.data.embedded_registration_enabled ?? false,
            });
        }
    }, [eventSettingsQuery.isFetched]);

    const isPaid = !!event && !isFreeEvent(event);

    const handleSubmit = (values: typeof form.values) => {
        updateMutation.mutate({
            eventSettings: values,
            eventId: eventId,
        }, {
            onSuccess: () => {
                showSuccess(t`Successfully Updated Settings`);
            },
            onError: (error) => {
                formErrorHandle(form, error);
            }
        });
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Registration built into the page`}
                description={t`Replace the ticket list and the details page with a step by step form displayed directly on the event page, for free and paid events.`}
            />
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending}>
                    <Switch
                        {...form.getInputProps('embedded_registration_enabled', {type: 'checkbox'})}
                        label={t`Enable the built-in registration`}
                        description={t`Visitors answer one question at a time, add the people coming with them and confirm without leaving the event page.`}
                    />

                    {isPaid && (
                        <Alert mt="md" variant="light" color="gray" icon={<IconInfoCircle/>}>
                            {t`Paid event: the buyer chooses their tickets and enters their details on the event page, then goes straight to the usual payment page. Service fees are shown in the order summary, just before payment.`}
                        </Alert>
                    )}

                    <Button loading={updateMutation.isPending} type="submit" mt="xl">
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
};
