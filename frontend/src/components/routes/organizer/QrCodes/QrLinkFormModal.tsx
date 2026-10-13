import {useMemo} from "react";
import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {t} from "@lingui/macro";
import {
    ActionIcon,
    Alert,
    Button,
    ColorInput,
    Group,
    Radio,
    Select,
    SimpleGrid,
    Stack,
    Text,
    Textarea,
    TextInput,
} from "@mantine/core";
import {IconPlus, IconTrash} from "@tabler/icons-react";
import {Modal} from "../../../common/Modal";
import {QrLink, QrLinkDestinationType, QrLinkSocialLink, QrLinkSocialPlatform} from "../../../../types.ts";
import {QrLinkRequest} from "../../../../api/qr-link.client.ts";
import {useCreateQrLink} from "../../../../mutations/useCreateQrLink.ts";
import {useUpdateQrLink} from "../../../../mutations/useUpdateQrLink.ts";
import {useGetOrganizerEvents} from "../../../../queries/useGetOrganizerEvents.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../../utilites/notifications.tsx";
import {DEFAULT_BACKGROUND, DEFAULT_FOREGROUND, QrCodeImage} from "./QrCodeImage.tsx";
import classes from "./QrCodes.module.scss";

const PLATFORMS: { value: QrLinkSocialPlatform, label: string }[] = [
    {value: 'instagram', label: 'Instagram'},
    {value: 'facebook', label: 'Facebook'},
    {value: 'tiktok', label: 'TikTok'},
    {value: 'whatsapp', label: 'WhatsApp'},
    {value: 'snapchat', label: 'Snapchat'},
    {value: 'youtube', label: 'YouTube'},
    {value: 'x', label: 'X'},
    {value: 'linkedin', label: 'LinkedIn'},
    {value: 'telegram', label: 'Telegram'},
    {value: 'website', label: 'Web'},
];

interface FormValues {
    name: string;
    destination_type: QrLinkDestinationType;
    destination_url: string;
    event_id: string | null;
    title: string;
    description: string;
    social_links: QrLinkSocialLink[];
    foreground: string;
    background: string;
}

interface QrLinkFormModalProps {
    qrLink?: QrLink;
    onClose: () => void;
}

export const QrLinkFormModal = ({qrLink, onClose}: QrLinkFormModalProps) => {
    const {organizerId} = useParams();
    const createMutation = useCreateQrLink();
    const updateMutation = useUpdateQrLink();
    const formErrorHandle = useFormErrorResponseHandler();
    const eventsQuery = useGetOrganizerEvents(organizerId!, {
        perPage: 100,
        sortBy: 'start_date',
        sortDirection: 'desc',
    });

    const eventOptions = useMemo(() => (eventsQuery.data?.data || []).map(event => ({
        value: String(event.id),
        label: event.title,
    })), [eventsQuery.data]);

    const form = useForm<FormValues>({
        initialValues: {
            name: qrLink?.name || '',
            destination_type: qrLink?.destination_type || 'LINK_PAGE',
            destination_url: qrLink?.destination_url || '',
            event_id: qrLink?.event_id ? String(qrLink.event_id) : null,
            title: qrLink?.title || '',
            description: qrLink?.description || '',
            social_links: qrLink?.social_links || [],
            foreground: qrLink?.design?.foreground || DEFAULT_FOREGROUND,
            background: qrLink?.design?.background || DEFAULT_BACKGROUND,
        },
        validate: {
            name: (value) => value.trim() ? null : t`Please give this QR code a name`,
        },
    });

    const type = form.values.destination_type;
    const isPending = createMutation.isPending || updateMutation.isPending;

    const submit = (values: FormValues) => {
        const payload: QrLinkRequest = {
            name: values.name.trim(),
            destination_type: values.destination_type,
            destination_url: values.destination_type === 'URL' ? values.destination_url.trim() : null,
            event_id: values.destination_type === 'URL' ? null : (values.event_id ? Number(values.event_id) : null),
            title: values.destination_type === 'LINK_PAGE' ? values.title.trim() || null : null,
            description: values.destination_type === 'LINK_PAGE' ? values.description.trim() || null : null,
            social_links: values.destination_type === 'LINK_PAGE'
                ? values.social_links.filter(link => link.url.trim()).map(link => ({...link, url: link.url.trim()}))
                : [],
            design: {foreground: values.foreground, background: values.background},
            status: qrLink?.status || 'ACTIVE',
        };

        const callbacks = {
            onSuccess: () => {
                showSuccess(qrLink ? t`QR code updated` : t`QR code created`);
                onClose();
            },
            onError: (error: any) => formErrorHandle(form, error),
        };

        if (qrLink) {
            updateMutation.mutate({organizerId: organizerId!, qrLinkId: qrLink.id, qrLink: payload}, callbacks);
        } else {
            createMutation.mutate({organizerId: organizerId!, qrLink: payload}, callbacks);
        }
    };

    return (
        <Modal opened onClose={onClose} heading={qrLink ? t`Edit QR code` : t`New QR code`}>
            <form onSubmit={form.onSubmit(submit)}>
                <div className={classes.formLayout}>
                    <Stack gap="md">
                        <TextInput
                            {...form.getInputProps('name')}
                            required
                            label={t`Where will this QR code be displayed?`}
                            description={t`One QR code per medium lets you compare them: poster, flyer, screen, stand...`}
                            placeholder={t`Market poster`}
                        />

                        <Radio.Group {...form.getInputProps('destination_type')} label={t`What does it open?`}>
                            <Stack gap="xs" mt="xs">
                                <Radio value="LINK_PAGE" label={t`Link page (recommended)`}
                                       description={t`Your social networks and a ticket button, on a page where your pixel can measure visits.`}/>
                                <Radio value="EVENT" label={t`Event page`}
                                       description={t`Opens the ticketing page directly. Sales are attributed to this QR code.`}/>
                                <Radio value="URL" label={t`Another link`}
                                       description={t`Instagram profile, video, website... Scans are counted, but no pixel can be triggered.`}/>
                            </Stack>
                        </Radio.Group>

                        {type === 'URL' && (
                            <TextInput
                                {...form.getInputProps('destination_url')}
                                required
                                label={t`Link`}
                                placeholder="https://instagram.com/..."
                            />
                        )}

                        {type !== 'URL' && (
                            <Select
                                {...form.getInputProps('event_id')}
                                required={type === 'EVENT'}
                                clearable={type === 'LINK_PAGE'}
                                searchable
                                data={eventOptions}
                                label={type === 'EVENT' ? t`Event` : t`Ticket button`}
                                description={type === 'LINK_PAGE' ? t`Without an event, the button opens your organizer page.` : undefined}
                                placeholder={t`Choose an event`}
                            />
                        )}

                        {type === 'LINK_PAGE' && (
                            <>
                                <TextInput {...form.getInputProps('title')} label={t`Page title`}
                                           placeholder={t`Defaults to the event or organizer name`}/>
                                <Textarea {...form.getInputProps('description')} label={t`Short text`} autosize minRows={2}
                                          maxLength={500}/>
                                <div>
                                    <Text size="sm" fw={500}>{t`Social networks`}</Text>
                                    <Text size="xs" c="dimmed" mb="xs">
                                        {t`Leave empty to use the links from your organizer settings.`}
                                    </Text>
                                    <Stack gap="xs">
                                        {form.values.social_links.map((_, index) => (
                                            <Group key={index} gap="xs" wrap="nowrap">
                                                <Select
                                                    {...form.getInputProps(`social_links.${index}.platform`)}
                                                    data={PLATFORMS}
                                                    w={140}
                                                    allowDeselect={false}
                                                />
                                                <TextInput
                                                    {...form.getInputProps(`social_links.${index}.url`)}
                                                    placeholder="https://"
                                                    style={{flex: 1}}
                                                />
                                                <ActionIcon variant="subtle" color="red" aria-label={t`Remove`}
                                                            onClick={() => form.removeListItem('social_links', index)}>
                                                    <IconTrash size={16}/>
                                                </ActionIcon>
                                            </Group>
                                        ))}
                                        {form.values.social_links.length < 12 && (
                                            <Button variant="subtle" size="xs" leftSection={<IconPlus size={14}/>}
                                                    w="fit-content"
                                                    onClick={() => form.insertListItem('social_links', {platform: 'instagram', url: ''})}>
                                                {t`Add a network`}
                                            </Button>
                                        )}
                                    </Stack>
                                </div>
                            </>
                        )}

                        {type === 'URL' && (
                            <Alert color="yellow" variant="light">
                                {t`With a direct link, visitors leave immediately: your Meta pixel cannot record them. Choose the link page to build retargeting audiences.`}
                            </Alert>
                        )}
                    </Stack>

                    <Stack gap="md" align="center" className={classes.previewColumn}>
                        <QrCodeImage
                            value={qrLink?.public_url || `${window.location.origin}/q/preview`}
                            design={{foreground: form.values.foreground, background: form.values.background}}
                            size={150}
                        />
                        <SimpleGrid cols={1} spacing="xs" w="100%">
                            <ColorInput {...form.getInputProps('foreground')} label={t`Color`} format="hex" size="xs"/>
                            <ColorInput {...form.getInputProps('background')} label={t`Background`} format="hex" size="xs"/>
                        </SimpleGrid>
                        <Text size="xs" c="dimmed" ta="center">
                            {t`Keep a dark color on a light background so every phone can scan it.`}
                        </Text>
                    </Stack>
                </div>

                <Group justify="flex-end" mt="xl">
                    <Button variant="default" onClick={onClose}>{t`Cancel`}</Button>
                    <Button type="submit" loading={isPending}>{qrLink ? t`Save` : t`Create the QR code`}</Button>
                </Group>
            </form>
        </Modal>
    );
};
