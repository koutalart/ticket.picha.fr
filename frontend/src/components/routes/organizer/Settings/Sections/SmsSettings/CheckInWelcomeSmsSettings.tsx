import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {Switch, Text} from "@mantine/core";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {useGetSmsSettings} from "../../../../../../queries/useGetSmsSettings.ts";
import {useUpdateSmsSettings} from "../../../../../../mutations/useUpdateSmsSettings.ts";
import {showError, showSuccess} from "../../../../../../utilites/notifications.tsx";

/**
 * Welcome SMS sent when an attendee's ticket is scanned at the door for the first time.
 */
export const CheckInWelcomeSmsSettings = () => {
    const {organizerId} = useParams();
    const settingsQuery = useGetSmsSettings(organizerId);
    const updateMutation = useUpdateSmsSettings();
    const enabled = !!settingsQuery.data?.checkin_welcome_sms_enabled;

    const toggle = (value: boolean) => {
        updateMutation.mutate({
            organizerId: organizerId!,
            settings: {checkin_welcome_sms_enabled: value},
        }, {
            onSuccess: () => showSuccess(value ? t`Welcome SMS turned on` : t`Welcome SMS turned off`),
            onError: () => showError(t`Unable to save the welcome SMS setting`),
        });
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Welcome SMS at the door`}
                description={t`When a ticket is scanned for the first time, the attendee receives a short, friendly SMS that welcomes them by first name, different every time.`}
            />
            <Switch
                checked={enabled}
                disabled={settingsQuery.isLoading || updateMutation.isPending}
                onChange={(event) => toggle(event.currentTarget.checked)}
                label={t`Send a welcome SMS when a ticket is scanned`}
                mb="xs"
            />
            <Text size="xs" c="dimmed">
                {t`Sent to the attendee's phone, or to the order's phone. One SMS per phone number and per event, so a group with one buyer receives a single SMS. Paper tickets without a phone receive nothing.`}
            </Text>
        </Card>
    );
};
