import {Modal} from "../../common/Modal";
import {GenericModalProps, ProductCategory, ProductType} from "../../../types.ts";
import {Button} from "../../common/Button";
import {useNavigate, useParams} from "react-router";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {useForm} from "@mantine/form";
import {LoadingOverlay, Select, Switch, Text, TextInput} from "@mantine/core";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {CreateAttendeeRequest} from "../../../api/attendee.client.ts";
import {useCreateAttendee} from "../../../mutations/useCreateAttendee.ts";
import {showSuccess} from "../../../utilites/notifications.tsx";
import {t} from "@lingui/macro";
import {useEffect} from "react";
import {InputGroup} from "../../common/InputGroup";
import {
    getClientLocale,
    localeToFlagEmojiMap,
    localeToNameMap,
    SupportedLocales
} from "../../../locales.ts";
import {getLocaleName} from "../../../utilites/localeNames.ts";
import {ProductSelector} from "../../common/ProductSelector";
import {getProductsFromEvent} from "../../../utilites/helpers.ts";
import {formatCurrency} from "../../../utilites/currency.ts";

export const CreateAttendeeModal = ({onClose}: GenericModalProps) => {
    const {eventId} = useParams();
    const errorHandler = useFormErrorResponseHandler();
    const {data: event, isFetched: isEventFetched} = useGetEvent(eventId);
    const mutation = useCreateAttendee();
    const navigate = useNavigate();
    const eventProducts = getProductsFromEvent(event);
    const eventHasProducts = eventProducts && eventProducts?.length > 0;

    const form = useForm<CreateAttendeeRequest>({
        initialValues: {
            product_id: undefined,
            email: '',
            first_name: '',
            last_name: '',
            is_free: false,
            send_confirmation_email: true,
            locale: getClientLocale() as SupportedLocales,
        },
    });

    const selectedProduct = eventProducts?.find(product => product.id == form.values.product_id);
    const selectedPrice = selectedProduct?.prices?.find(price => String(price.id) === String(form.values.product_price_id))
        ?? selectedProduct?.prices?.[0];

    useEffect(() => {
        if (event?.product_categories) {
            form.setFieldValue('product_price_id', String(selectedProduct?.prices?.[0]?.id));
        }
    }, [form.values.product_id]);

    const handleSubmit = (values: CreateAttendeeRequest) => {
        mutation.mutate({
            eventId: eventId,
            attendeeData: values,
        }, {
            onSuccess: () => {
                showSuccess(t`Successfully created attendee`);
                onClose();
            },
            onError: (error) => errorHandler(form, error),
        })
    };

    if (!event?.product_categories) {
        return (
            <LoadingOverlay visible/>
        )
    }

    if (isEventFetched && !eventHasProducts) {
        return (
            <Modal opened onClose={onClose} heading={t`Manually Add Attendee`}>
                <p>{t`You must create a ticket before you can manually add an attendee.`}</p>
                <Button
                    fullWidth
                    variant={'light'}
                    onClick={() => {
                        navigate(`/manage/event/${eventId}/products`)
                    }}
                >
                    {t`Manage tickets`}
                </Button>
            </Modal>
        )
    }

    return (
        <Modal opened onClose={onClose} heading={t`Manually Add Attendee`}>
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <InputGroup>
                    <TextInput
                        {...form.getInputProps('first_name')}
                        label={t`First name`}
                        placeholder={t`Patrick`}
                        required
                    />

                    <TextInput
                        {...form.getInputProps('last_name')}
                        label={t`Last name`}
                        placeholder={t`Johnson`}
                        required
                    />
                </InputGroup>
                <TextInput
                    {...form.getInputProps('email')}
                    label={t`Email address`}
                    placeholder={t`patrick@acme.com`}
                    required
                />

                <Select
                    required
                    data={Object.keys(localeToNameMap).map(locale => ({
                        value: locale,
                        label: localeToFlagEmojiMap[locale as SupportedLocales] + ' ' + getLocaleName(locale as SupportedLocales),
                    }))}
                    {...form.getInputProps('locale')}
                    label={t`Language`}
                    placeholder={t`English`}
                    description={t`The language the attendee will receive emails in.`}
                />

                <ProductSelector
                    placeholder={t`Select Ticket`}
                    label={t`Ticket`}
                    productCategories={event.product_categories as ProductCategory[]}
                    form={form}
                    productFieldName={'product_id'}
                    multiSelect={false}
                    showTierSelector={true}
                    includedProductTypes={[ProductType.Ticket]}
                />

                {selectedPrice && (
                    <Text mt={20} size="sm">
                        {t`Ticket price`}: <strong>{form.values.is_free
                            ? t`Free`
                            : formatCurrency(Number(selectedPrice.price), event?.currency)}</strong>
                    </Text>
                )}

                <Switch
                    mt={12}
                    label={t`Free (no payment)`}
                    description={t`The price of the ticket is applied automatically. Tick to add this attendee free of charge.`}
                    {...form.getInputProps('is_free', {type: 'checkbox'})}
                />

                <Switch
                    mt={20}
                    label={t`Send order confirmation and ticket email`}
                    {...form.getInputProps('send_confirmation_email', {type: 'checkbox'})}
                />
                <Button type="submit" fullWidth mt="xl" disabled={mutation.isPending}>
                    {mutation.isPending ? t`Working` + '...' : t`Create Attendee`}
                </Button>
            </form>
        </Modal>
    );
}
