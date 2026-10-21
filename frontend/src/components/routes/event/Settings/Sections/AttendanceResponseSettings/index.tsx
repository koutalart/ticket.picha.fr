import {t} from "@lingui/macro";
import {Button, Code, Switch, Text} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useParams} from "react-router";
import {useEffect} from "react";
import {Card} from "../../../../../common/Card";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {useUpdateEventSettings} from "../../../../../../mutations/useUpdateEventSettings.ts";
import {useGetEventSettings} from "../../../../../../queries/useGetEventSettings.ts";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {ATTENDANCE_URL_VARIABLE} from "../../../../../../constants/attendance.ts";

export const AttendanceResponseSettings = () => {
    const {eventId} = useParams();
    const eventSettingsQuery = useGetEventSettings(eventId);
    const updateMutation = useUpdateEventSettings();
    const form = useForm({
        initialValues: {
            allow_attendee_attendance_response: false,
        }
    });
    const formErrorHandle = useFormErrorResponseHandler();

    useEffect(() => {
        if (eventSettingsQuery?.isFetched && eventSettingsQuery?.data) {
            form.setValues({
                allow_attendee_attendance_response: eventSettingsQuery.data.allow_attendee_attendance_response ?? false,
            });
        }
    }, [eventSettingsQuery.isFetched]);

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
                heading={t`Attendance confirmation`}
                description={t`Send each attendee a personal link to confirm they are coming or to cancel. A cancelled place is released and offered to the waiting list.`}
            />
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending}>
                    <Switch
                        {...form.getInputProps('allow_attendee_attendance_response', {type: 'checkbox'})}
                        label={t`Attendees can confirm or cancel their attendance`}
                        description={t`Adds "I'm coming" and "I'm no longer coming" buttons to the order page. Cancelling does not trigger a refund.`}
                    />

                    <Text size="sm" c="dimmed" mt="md">
                        {t`To include the personal link in a scheduled reminder or in the ticket email, insert this variable:`}
                        {' '}<Code>{ATTENDANCE_URL_VARIABLE}</Code>
                    </Text>

                    <Button loading={updateMutation.isPending} type="submit" mt="xl">
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
};
