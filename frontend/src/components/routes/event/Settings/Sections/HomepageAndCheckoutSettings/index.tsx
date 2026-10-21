import {t} from "@lingui/macro";
import {Button, NumberInput, Switch} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useParams} from "react-router";
import {useEffect} from "react";
import {AgeRestrictionRule, EventSettings} from "../../../../../../types.ts";
import {Card} from "../../../../../common/Card";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {useUpdateEventSettings} from "../../../../../../mutations/useUpdateEventSettings.ts";
import {useGetEventSettings} from "../../../../../../queries/useGetEventSettings.ts";
import {Editor} from "../../../../../common/Editor";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {isEmptyHtml} from "../../../../../../utilites/helpers.ts";
import {CustomSelect, ItemProps} from "../../../../../common/CustomSelect";
import {IconUser, IconUserCheck, IconUserShield, IconUsers} from "@tabler/icons-react";

export const HomepageAndCheckoutSettings = () => {
    const {eventId} = useParams();
    const eventSettingsQuery = useGetEventSettings(eventId);
    const updateMutation = useUpdateEventSettings();
    const form = useForm({
        initialValues: {
            pre_checkout_message: '',
            post_checkout_message: '',
            order_timeout_in_minutes: 15,
            attendee_details_collection_method: 'PER_TICKET' as 'PER_TICKET' | 'PER_ORDER',
            show_marketing_opt_in: true,
            allow_copy_details_to_all_attendees: true,
            age_restriction_rule: 'NONE' as AgeRestrictionRule,
            age_restriction_minimum_age: 18,
        },
        transformValues: (values) => ({
            ...values,
            pre_checkout_message: isEmptyHtml(values.pre_checkout_message) ? null : values.pre_checkout_message,
            post_checkout_message: isEmptyHtml(values.post_checkout_message) ? null : values.post_checkout_message,
        }),
    });

    const attendeeCollectionOptions: ItemProps[] = [
        {
            icon: <IconUsers/>,
            label: t`Per ticket`,
            value: 'PER_TICKET',
            description: t`Collect attendee details for each ticket purchased.`,
        },
        {
            icon: <IconUser/>,
            label: t`Per order`,
            value: 'PER_ORDER',
            description: t`Use order details for all attendees. Attendee names and emails will match the buyer's information.`,
        },
    ];
    const ageRestrictionOptions: ItemProps[] = [
        {
            icon: <IconUsers/>,
            label: t`No restriction`,
            value: 'NONE',
            description: t`Attendees of all ages can register. No date of birth is requested.`,
        },
        {
            icon: <IconUserCheck/>,
            label: t`Minors must be accompanied`,
            value: 'ACCOMPANIED_MINORS',
            description: t`Attendees under the age limit are only accepted if the same order includes at least one adult.`,
        },
        {
            icon: <IconUserShield/>,
            label: t`Strict minimum age`,
            value: 'MINIMUM_AGE',
            description: t`Any attendee under the age limit is refused.`,
        },
    ];
    const formErrorHandle = useFormErrorResponseHandler();

    useEffect(() => {
        if (eventSettingsQuery?.isFetched && eventSettingsQuery?.data) {
            form.setValues({
                pre_checkout_message: eventSettingsQuery.data.pre_checkout_message,
                post_checkout_message: eventSettingsQuery.data.post_checkout_message,
                order_timeout_in_minutes: eventSettingsQuery.data.order_timeout_in_minutes,
                attendee_details_collection_method: eventSettingsQuery.data.attendee_details_collection_method || 'PER_TICKET',
                show_marketing_opt_in: eventSettingsQuery.data.show_marketing_opt_in ?? true,
                allow_copy_details_to_all_attendees: eventSettingsQuery.data.allow_copy_details_to_all_attendees ?? true,
                age_restriction_rule: eventSettingsQuery.data.age_restriction_rule || 'NONE',
                age_restriction_minimum_age: eventSettingsQuery.data.age_restriction_minimum_age ?? 18,
            });
        }
    }, [eventSettingsQuery.isFetched]);

    const handleSubmit = (values: Partial<EventSettings>) => {
        updateMutation.mutate({
            eventSettings: values,
            eventId: eventId,
        }, {
            onSuccess: () => {
                showSuccess(t`Successfully Updated Homepage Settings`);
            },
            onError: (error) => {
                formErrorHandle(form, error);
            }
        });
    }

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Checkout Settings`}
                description={t`Customize the event homepage and checkout messaging`}
            />
            <form onSubmit={form.onSubmit(handleSubmit as any)}>
                <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending}>
                    <Editor
                        label={t`Pre Checkout message`}
                        value={form.values.pre_checkout_message || ''}
                        description={t`Shown to the customer before they checkout`}
                        onChange={(value) => form.setFieldValue('pre_checkout_message', value)}
                    />

                    <Editor
                        label={t`Post Checkout message`}
                        value={form.values.post_checkout_message || ''}
                        description={(
                            <>
                                <p>
                                    {t`Shown to the customer after they checkout, on the order summary page.`}
                                </p>
                                <p>
                                    {t`This message will only be shown if order is completed successfully. Orders awaiting payment will not show this message`}
                                </p>
                            </>
                        )}
                        onChange={(value) => form.setFieldValue('post_checkout_message', value)}
                    />

                    <CustomSelect
                        optionList={attendeeCollectionOptions}
                        form={form}
                        name={'attendee_details_collection_method'}
                        label={t`Attendee information collection`}
                        required
                    />

                    <CustomSelect
                        optionList={ageRestrictionOptions}
                        form={form}
                        name={'age_restriction_rule'}
                        label={t`Attendee age check`}
                        description={t`When a rule is active, a date of birth is required for each attendee. Age is calculated on the event start date.`}
                        required
                    />

                    {form.values.age_restriction_rule !== 'NONE' && (
                        <NumberInput
                            label={t`Age limit`}
                            description={t`Attendees younger than this age on the event date are considered minors.`}
                            min={1}
                            max={99}
                            allowDecimal={false}
                            {...form.getInputProps('age_restriction_minimum_age')}
                        />
                    )}

                    <NumberInput
                        label={t`Order timeout`}
                        description={t`How many minutes the customer has to complete their order. We recommend at least 15 minutes`}
                        {...form.getInputProps('order_timeout_in_minutes')}
                    />

                    <Switch
                        mt="md"
                        label={t`Show marketing opt-in checkbox`}
                        description={t`Display a checkbox allowing customers to opt-in to receive marketing communications from this event organizer.`}
                        {...form.getInputProps('show_marketing_opt_in', {type: 'checkbox'})}
                    />

                    <Switch
                        mt="md"
                        label={t`Allow buyers to copy their details to all attendees`}
                        description={t`When enabled, buyers can copy their own name and email onto all attendees at once. Turn this off to remove the "All attendees" option; buyers can still copy to the first attendee, and the rest must be entered individually.`}
                        {...form.getInputProps('allow_copy_details_to_all_attendees', {type: 'checkbox'})}
                    />

                    <Button loading={updateMutation.isPending} type={'submit'}>
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
}
