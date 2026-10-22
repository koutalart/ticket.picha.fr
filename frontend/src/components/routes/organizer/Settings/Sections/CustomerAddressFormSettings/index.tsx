import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {useEffect} from "react";
import {t} from "@lingui/macro";
import {Button, Radio, Stack} from "@mantine/core";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {CustomerAddressForm} from "../../../../../../types.ts";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {useGetOrganizerSettings} from "../../../../../../queries/useGetOrganizerSettings.ts";
import {useUpdateOrganizerSettings} from "../../../../../../mutations/useUpdateOrganizerSettings.ts";

export const CustomerAddressFormSettings = () => {
    const {organizerId} = useParams();
    const organizerSettingsQuery = useGetOrganizerSettings(organizerId);
    const updateMutation = useUpdateOrganizerSettings();
    const formErrorHandle = useFormErrorResponseHandler();
    const form = useForm<{ customer_address_form: CustomerAddressForm }>({
        initialValues: {
            customer_address_form: 'vous',
        },
    });

    useEffect(() => {
        if (organizerSettingsQuery?.isFetched && organizerSettingsQuery?.data) {
            form.setValues({
                customer_address_form: organizerSettingsQuery.data.customer_address_form === 'tu' ? 'tu' : 'vous',
            });
        }
    }, [organizerSettingsQuery.isFetched]);

    const handleSubmit = (values: { customer_address_form: CustomerAddressForm }) => {
        updateMutation.mutate({
            organizerSettings: {
                customer_address_form: values.customer_address_form,
            },
            organizerId: organizerId,
        }, {
            onSuccess: () => {
                showSuccess(t`Tone updated`);
            },
            onError: (error) => {
                formErrorHandle(form, error);
            }
        });
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Tone with your customers`}
                description={t`Choose how the French pages of the ticketing site address your customers: event page, booking, order confirmation and ticket.`}
            />
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={organizerSettingsQuery.isLoading || updateMutation.isPending}>
                    <Radio.Group {...form.getInputProps('customer_address_form')} mb="md">
                        <Stack gap="xs">
                            <Radio value="vous" label={t`Formal (vous)`}/>
                            <Radio value="tu" label={t`Informal (tu)`}/>
                        </Stack>
                    </Radio.Group>
                    <Button loading={updateMutation.isPending} type={'submit'}>
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
};
