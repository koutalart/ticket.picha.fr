import {useEffect} from "react";
import {t} from "@lingui/macro";
import {useForm} from "@mantine/form";
import {ActionIcon, Button, ColorInput, Fieldset, NumberInput, Switch, Text, TextInput} from "@mantine/core";
import {IconPlus, IconTrash} from "@tabler/icons-react";
import {IdParam, LoyaltyProgram} from "../../../../types.ts";
import {useUpsertLoyaltyProgram} from "../../../../mutations/useUpsertLoyaltyProgram.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../../utilites/notifications.tsx";
import classes from "./Loyalty.module.scss";

const MAX_LOCATIONS = 10;

const emptyProgram = (): LoyaltyProgram => ({
    name: t`Loyalty card`,
    reward_description: t`1 free menu`,
    stamps_required: 9,
    background_color: '#1F2937',
    foreground_color: '#FFFFFF',
    label_color: '#D1D5DB',
    logo_url: '',
    locations: [],
    max_distance: null,
    beacon_uuid: '',
    beacon_major: null,
    beacon_minor: null,
    beacon_relevant_text: '',
    is_active: false,
});

const blankToNull = (value?: string | null) => (value && value.trim() !== '' ? value.trim() : null);

interface LoyaltyProgramFormProps {
    organizerId: IdParam;
    program: LoyaltyProgram | null;
}

export const LoyaltyProgramForm = ({organizerId, program}: LoyaltyProgramFormProps) => {
    const upsertMutation = useUpsertLoyaltyProgram();
    const handleFormError = useFormErrorResponseHandler();
    const form = useForm<LoyaltyProgram>({initialValues: emptyProgram()});

    useEffect(() => {
        if (program) {
            form.setValues({
                ...emptyProgram(),
                ...program,
                logo_url: program.logo_url ?? '',
                beacon_uuid: program.beacon_uuid ?? '',
                beacon_relevant_text: program.beacon_relevant_text ?? '',
                locations: program.locations ?? [],
            });
            form.resetDirty();
        }
    }, [program]);

    const handleSubmit = (values: LoyaltyProgram) => {
        const beaconUuid = blankToNull(values.beacon_uuid);
        upsertMutation.mutate({
            organizerId,
            program: {
                ...values,
                logo_url: blankToNull(values.logo_url),
                beacon_uuid: beaconUuid,
                beacon_major: beaconUuid ? values.beacon_major ?? null : null,
                beacon_minor: beaconUuid ? values.beacon_minor ?? null : null,
                beacon_relevant_text: beaconUuid ? blankToNull(values.beacon_relevant_text) : null,
                locations: values.locations.map((location) => ({
                    latitude: Number(location.latitude),
                    longitude: Number(location.longitude),
                    relevant_text: blankToNull(location.relevant_text),
                })),
            },
        }, {
            onSuccess: () => showSuccess(t`Loyalty card saved`),
            onError: (error) => handleFormError(form, error),
        });
    };

    return (
        <form onSubmit={form.onSubmit(handleSubmit)} className={classes.form}>
            <Fieldset legend={t`The card`}>
                <TextInput required label={t`Card name`} maxLength={80} {...form.getInputProps('name')}/>
                <TextInput required label={t`Reward`} maxLength={120}
                           description={t`What the customer gets once the card is full`}
                           {...form.getInputProps('reward_description')}/>
                <NumberInput required label={t`Stamps needed`} min={2} max={50}
                             description={t`One stamp per validated order`}
                             {...form.getInputProps('stamps_required')}/>
                <TextInput label={t`Logo URL`} placeholder="https://"
                           description={t`Square image in PNG, shown on the card`}
                           {...form.getInputProps('logo_url')}/>
                <div className={classes.colors}>
                    <ColorInput label={t`Background`} format="hex" {...form.getInputProps('background_color')}/>
                    <ColorInput label={t`Text`} format="hex" {...form.getInputProps('foreground_color')}/>
                    <ColorInput label={t`Labels`} format="hex" {...form.getInputProps('label_color')}/>
                </div>
                <Switch mt="md" label={t`Customers can add the card`} {...form.getInputProps('is_active', {type: 'checkbox'})}/>
            </Fieldset>

            <Fieldset legend={t`Nearby notification`}>
                <Text size="sm" c="dimmed" mb="sm">
                    {t`When a customer passes near one of these places, the card shows up on their lock screen with your message. 10 places at most.`}
                </Text>
                {form.values.locations.map((_, index) => (
                    <div key={index} className={classes.location}>
                        <NumberInput label={t`Latitude`} decimalScale={7} min={-90} max={90} required
                                     {...form.getInputProps(`locations.${index}.latitude`)}/>
                        <NumberInput label={t`Longitude`} decimalScale={7} min={-180} max={180} required
                                     {...form.getInputProps(`locations.${index}.longitude`)}/>
                        <TextInput className={classes.locationText} label={t`Lock screen message`} maxLength={120}
                                   placeholder={t`Your card is ready, see you at the counter`}
                                   {...form.getInputProps(`locations.${index}.relevant_text`)}/>
                        <ActionIcon variant="subtle" color="red" mt={24} aria-label={t`Remove this place`}
                                    onClick={() => form.removeListItem('locations', index)}>
                            <IconTrash size={18}/>
                        </ActionIcon>
                    </div>
                ))}
                <Button variant="light" leftSection={<IconPlus size={16}/>}
                        disabled={form.values.locations.length >= MAX_LOCATIONS}
                        onClick={() => form.insertListItem('locations', {latitude: '', longitude: '', relevant_text: ''})}>
                    {t`Add a place`}
                </Button>
                <NumberInput mt="md" label={t`Distance in meters (Apple)`} min={10} max={1000}
                             description={t`Leave empty to let the iPhone decide`}
                             {...form.getInputProps('max_distance')}/>
            </Fieldset>

            <Fieldset legend={t`Bluetooth beacon (iPhone, optional)`}>
                <TextInput label={t`Beacon UUID`} placeholder="f7826da6-4fa2-4e98-8024-bc5b71e0893e"
                           {...form.getInputProps('beacon_uuid')}/>
                <div className={classes.colors}>
                    <NumberInput label={t`Major`} min={0} max={65535} {...form.getInputProps('beacon_major')}/>
                    <NumberInput label={t`Minor`} min={0} max={65535} {...form.getInputProps('beacon_minor')}/>
                </div>
                <TextInput label={t`Lock screen message`} maxLength={120}
                           {...form.getInputProps('beacon_relevant_text')}/>
            </Fieldset>

            <Button type="submit" loading={upsertMutation.isPending}>{t`Save`}</Button>
        </form>
    );
};
