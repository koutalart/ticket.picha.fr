import {useEffect} from "react";
import {t} from "@lingui/macro";
import {useForm} from "@mantine/form";
import {ActionIcon, Alert, Badge, Button, Group, Select, Switch, Text, Textarea, TextInput} from "@mantine/core";
import {IconTrash, IconUsersGroup} from "@tabler/icons-react";
import {Card} from "../../../common/Card";
import {HeadingWithDescription} from "../../../common/Card/CardHeading";
import {IdParam, OrganizerSmsSettings, OrganizerTeamContact, OrganizerTeamContactRole} from "../../../../types.ts";
import {useGetOrganizerTeamContacts} from "../../../../queries/useGetOrganizerTeamContacts.ts";
import {useSaveOrganizerTeamContact} from "../../../../mutations/useSaveOrganizerTeamContact.ts";
import {useDeleteOrganizerTeamContact} from "../../../../mutations/useDeleteOrganizerTeamContact.ts";
import {useUpdateSmsSettings} from "../../../../mutations/useUpdateSmsSettings.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import classes from "./TeamView.module.scss";

const MAX_INSTRUCTIONS_LENGTH = 2000;

const roleLabel = (role: OrganizerTeamContactRole) => role === 'ORGANIZER' ? t`Runs the event` : t`In copy`;

interface TeamViewProps {
    organizerId: IdParam;
    settings?: OrganizerSmsSettings;
}

export const TeamView = ({organizerId, settings}: TeamViewProps) => {
    const contactsQuery = useGetOrganizerTeamContacts(organizerId);
    const saveMutation = useSaveOrganizerTeamContact();
    const deleteMutation = useDeleteOrganizerTeamContact();
    const updateSettingsMutation = useUpdateSmsSettings();
    const formErrorHandle = useFormErrorResponseHandler();
    const contacts = contactsQuery.data ?? [];

    const contactForm = useForm({
        initialValues: {phone: '', name: '', role: 'ORGANIZER' as OrganizerTeamContactRole},
        validate: {phone: (value) => value.trim() === '' ? t`Enter a phone number` : null},
    });

    const assistantForm = useForm({
        initialValues: {organizer_assistant_enabled: false, organizer_assistant_instructions: ''},
    });

    useEffect(() => {
        if (settings) {
            assistantForm.setValues({
                organizer_assistant_enabled: !!settings.organizer_assistant_enabled,
                organizer_assistant_instructions: settings.organizer_assistant_instructions || '',
            });
        }
    }, [settings?.organizer_assistant_enabled, settings?.organizer_assistant_instructions]);

    const addContact = (values: typeof contactForm.values) => {
        saveMutation.mutate({
            organizerId,
            contact: {phone: values.phone.trim(), name: values.name.trim() || null, role: values.role},
        }, {
            onSuccess: () => {
                contactForm.reset();
                showSuccess(t`Team contact saved`);
            },
            onError: (error) => formErrorHandle(contactForm, error),
        });
    };

    const removeContact = (contact: OrganizerTeamContact) => {
        deleteMutation.mutate({organizerId, contactId: contact.id}, {
            onSuccess: () => showSuccess(t`Team contact removed`),
            onError: () => showError(t`The contact could not be removed. Please try again.`),
        });
    };

    const saveAssistant = (values: typeof assistantForm.values) => {
        updateSettingsMutation.mutate({
            organizerId,
            settings: {
                organizer_assistant_enabled: values.organizer_assistant_enabled,
                organizer_assistant_instructions: values.organizer_assistant_instructions.trim() || null,
            },
        }, {
            onSuccess: () => showSuccess(t`Organizer assistant settings saved`),
            onError: (error) => formErrorHandle(assistantForm, error),
        });
    };

    return (
        <div className={classes.team}>
            <Card>
                <HeadingWithDescription
                    heading={t`Team contacts`}
                    description={t`The people who run this organizer. Their SMS never get an answer from the customer assistant and their conversations carry the Team badge.`}
                />

                <div className={classes.contacts}>
                    {contactsQuery.isFetched && contacts.length === 0 && (
                        <Text size="sm" c="dimmed">{t`No team contact yet.`}</Text>
                    )}
                    {contacts.map((contact) => (
                        <div key={contact.id} className={classes.contact}>
                            <div className={classes.contactInfo}>
                                <span className={classes.contactName}>{contact.name || contact.phone}</span>
                                {contact.name && <span className={classes.contactPhone}>{contact.phone}</span>}
                            </div>
                            <div className={classes.contactActions}>
                                <Badge variant="light" color={contact.role === 'ORGANIZER' ? 'violet' : 'gray'}>
                                    {roleLabel(contact.role)}
                                </Badge>
                                <ActionIcon
                                    variant="subtle"
                                    color="red"
                                    aria-label={t`Remove`}
                                    loading={deleteMutation.isPending && deleteMutation.variables?.contactId === contact.id}
                                    onClick={() => removeContact(contact)}
                                >
                                    <IconTrash size={16}/>
                                </ActionIcon>
                            </div>
                        </div>
                    ))}
                </div>

                <form onSubmit={contactForm.onSubmit(addContact)} className={classes.addForm}>
                    <TextInput {...contactForm.getInputProps('phone')} label={t`Phone number`} placeholder="0639 12 34 56"/>
                    <TextInput {...contactForm.getInputProps('name')} label={t`Name`}/>
                    <Select
                        {...contactForm.getInputProps('role')}
                        label={t`Role`}
                        allowDeselect={false}
                        data={[
                            {value: 'ORGANIZER', label: roleLabel('ORGANIZER')},
                            {value: 'COPY', label: roleLabel('COPY')},
                        ]}
                    />
                    <Button type="submit" loading={saveMutation.isPending}>{t`Add`}</Button>
                </form>
            </Card>

            <Card>
                <HeadingWithDescription
                    heading={t`Organizer assistant (Anicka)`}
                    description={t`Anicka answers at once the SMS of the people who run the event: she checks with them that registration works as they wish and collects their contact lists. She never acts herself.`}
                />

                {settings && !settings.assistant_available && (
                    <Alert color="gray" variant="light" mb="md">
                        {t`The SMS assistant is not available on this platform yet.`}
                    </Alert>
                )}

                <Alert color="blue" variant="light" mb="md" icon={<IconUsersGroup size={16}/>}>
                    <Text size="sm">
                        {t`Contacts in copy get no automatic answer. When a request commits to something (campaign, price, refund, any change), Anicka sends a holding reply and the platform supervision is alerted at once.`}
                    </Text>
                </Alert>

                <form onSubmit={assistantForm.onSubmit(saveAssistant)}>
                    <fieldset disabled={updateSettingsMutation.isPending || !settings?.assistant_available} style={{border: 0, padding: 0, margin: 0}}>
                        <Switch
                            {...assistantForm.getInputProps('organizer_assistant_enabled', {type: 'checkbox'})}
                            label={t`Answer the team automatically`}
                            description={t`Off by default.`}
                            mb="md"
                        />
                        <Textarea
                            {...assistantForm.getInputProps('organizer_assistant_instructions')}
                            label={t`Instructions for Anicka`}
                            description={t`Distinct from the instructions of the customer assistant. For example: what the organizer expects, the registration steps to check.`}
                            autosize
                            minRows={3}
                            maxRows={10}
                            maxLength={MAX_INSTRUCTIONS_LENGTH}
                            mb="md"
                        />
                        <Group justify="flex-end">
                            <Button type="submit" loading={updateSettingsMutation.isPending}>{t`Save`}</Button>
                        </Group>
                    </fieldset>
                </form>
            </Card>
        </div>
    );
};
