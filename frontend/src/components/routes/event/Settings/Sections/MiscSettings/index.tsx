import {t} from "@lingui/macro";
import {Button, NumberInput, Switch} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useParams} from "react-router";
import {useEffect} from "react";
import {EventSettings} from "../../../../../../types.ts";
import {Card} from "../../../../../common/Card";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {useUpdateEventSettings} from "../../../../../../mutations/useUpdateEventSettings.ts";
import {useGetEventSettings} from "../../../../../../queries/useGetEventSettings.ts";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {CustomSelect, ItemProps} from "../../../../../common/CustomSelect";
import {IconCoin, IconCoins} from "@tabler/icons-react";
import {SelfServiceSettings} from "../../../../../common/SelfServiceSettings";

export const MiscSettings = () => {
    const {eventId} = useParams();
    const eventSettingsQuery = useGetEventSettings(eventId);
    const updateMutation = useUpdateEventSettings();
    const form = useForm({
        initialValues: {
            price_display_mode: 'EXCLUSIVE',
            hide_getting_started_page: false,
            allow_attendee_self_edit: true,
            single_product_per_order: false,
            choose_category_first: false,
            refund_protection_deadline_hours: 0,
        }
    });
    const formErrorHandle = useFormErrorResponseHandler();

    useEffect(() => {
        if (eventSettingsQuery?.isFetched && eventSettingsQuery?.data) {
            form.setValues({
                price_display_mode: eventSettingsQuery.data.price_display_mode,
                hide_getting_started_page: eventSettingsQuery.data.hide_getting_started_page,
                allow_attendee_self_edit: eventSettingsQuery.data.allow_attendee_self_edit ?? true,
                single_product_per_order: eventSettingsQuery.data.single_product_per_order ?? false,
                choose_category_first: eventSettingsQuery.data.choose_category_first ?? false,
                refund_protection_deadline_hours: eventSettingsQuery.data.refund_protection_deadline_hours ?? 0,
            });
        }
    }, [eventSettingsQuery.isFetched]);

    const handleSubmit = (values: Partial<EventSettings>) => {
        updateMutation.mutate({
            eventSettings: values,
            eventId: eventId,
        }, {
            onSuccess: () => {
                showSuccess(t`Successfully Updated Misc Settings`);
            },
            onError: (error) => {
                formErrorHandle(form, error);
            }
        });
    }

    const priceOptions: ItemProps[] = [
        {
            icon: <IconCoins/>,
            label: t`Include tax and fees in the price`,
            value: 'INCLUSIVE',
            description: t`The price displayed to the customer will include taxes and fees.`,
        },
        {
            icon: <IconCoin/>,
            label: t`Show tax and fees separately`,
            value: 'EXCLUSIVE',
            description: t`The price displayed to the customer will not include taxes and fees. They will be shown separately`,
        },
    ];

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Miscellaneous Settings`}
                description={t`Customize the miscellaneous settings for this event`}
            />
            <form onSubmit={form.onSubmit(handleSubmit as any)}>
                <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending}>
                    <CustomSelect
                        optionList={priceOptions}
                        form={form}
                        name={'price_display_mode'}
                        label={t`Price display mode`}
                        required
                    />
                    {form.errors['price_display_mode'] && (
                        <div style={{color: 'red'}}>
                            {form.errors['price_display_mode']}
                        </div>
                    )}

                    <Switch
                        {...form.getInputProps('hide_getting_started_page', {type: 'checkbox'})}
                        label={t`Hide getting started page`}
                        description={t`Hide the getting started page from the sidebar`}
                    />

                    <Switch
                        mt={'md'}
                        mb={'md'}
                        {...form.getInputProps('single_product_per_order', {type: 'checkbox'})}
                        label={t`Single registration per order`}
                        description={t`Visitors choose a single ticket instead of quantities. Ideal for free registrations such as a candidate or exhibitor sign-up.`}
                    />

                    <Switch
                        mb={'md'}
                        {...form.getInputProps('choose_category_first', {type: 'checkbox'})}
                        label={t`Choose a category first`}
                        description={t`Visitors first pick a path (one big button per ticket category) before seeing its tickets.`}
                    />

                    <NumberInput
                        mb={'md'}
                        min={0}
                        max={8760}
                        allowDecimal={false}
                        {...form.getInputProps('refund_protection_deadline_hours')}
                        label={t`Cancellation protection deadline (hours)`}
                        description={t`Buyers with a cancellation protection product can get a refund from the live assistance up to this many hours before the event starts. 0 means until the event starts.`}
                    />

                    <SelfServiceSettings
                        value={form.values.allow_attendee_self_edit}
                        onChange={(value) => form.setFieldValue('allow_attendee_self_edit', value)}
                    />

                    <Button loading={updateMutation.isPending} type={'submit'}>
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
}
