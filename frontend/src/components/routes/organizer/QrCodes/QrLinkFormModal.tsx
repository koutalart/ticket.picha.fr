import {type CSSProperties, useMemo, useState} from "react";
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
    FileButton,
    Select,
    SimpleGrid,
    Slider,
    Stack,
    Text,
    Textarea,
    TextInput,
} from "@mantine/core";
import {IconPhoto, IconPlus, IconQrcode, IconTrash, IconUpload} from "@tabler/icons-react";
import {Modal} from "../../../common/Modal";
import {IdParam, QrCampaign, QrLink, QrLinkDesign, QrLinkDestinationType, QrLinkSocialLink, QrLinkSocialPlatform, QrPosterTemplate} from "../../../../types.ts";
import {imageClient} from "../../../../api/image.client.ts";
import {showError} from "../../../../utilites/notifications.tsx";
import {QrPosterPreview} from "./QrPosterPreview.tsx";
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
    qr_campaign_id: string | null;
    destination_type: QrLinkDestinationType;
    destination_url: string;
    event_id: string | null;
    title: string;
    description: string;
    social_links: QrLinkSocialLink[];
    foreground: string;
    background: string;
    template: QrPosterTemplate;
    background_image: QrPosterBackground | null;
    qr_position: number;
    qr_scale: number;
    caption: string;
    caption_color: string;
}

export interface QrPosterBackground {
    id: number;
    url: string;
    width?: number;
    height?: number;
}

interface QrLinkFormModalProps {
    qrLink?: QrLink;
    campaigns: QrCampaign[];
    defaultCampaignId?: IdParam;
    backgrounds: QrPosterBackground[];
    organizerName?: string;
    onClose: () => void;
}

export const QrLinkFormModal = ({qrLink, campaigns, defaultCampaignId, backgrounds, organizerName, onClose}: QrLinkFormModalProps) => {
    const [isUploading, setIsUploading] = useState(false);
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
            qr_campaign_id: qrLink
                ? (qrLink.qr_campaign_id ? String(qrLink.qr_campaign_id) : null)
                : (defaultCampaignId ? String(defaultCampaignId) : null),
            destination_type: qrLink?.destination_type || 'LINK_PAGE',
            destination_url: qrLink?.destination_url || '',
            event_id: qrLink?.event_id ? String(qrLink.event_id) : null,
            title: qrLink?.title || '',
            description: qrLink?.description || '',
            social_links: qrLink?.social_links || [],
            foreground: qrLink?.design?.foreground || DEFAULT_FOREGROUND,
            background: qrLink?.design?.background || DEFAULT_BACKGROUND,
            template: qrLink?.design?.template || 'none',
            background_image: qrLink?.design?.background_image_id && qrLink.design.background_image_url
                ? {
                    id: Number(qrLink.design.background_image_id),
                    url: qrLink.design.background_image_url,
                    width: qrLink.design.background_width,
                    height: qrLink.design.background_height,
                }
                : null,
            qr_position: qrLink?.design?.qr_position ?? 50,
            qr_scale: qrLink?.design?.qr_scale ?? 45,
            caption: qrLink?.design?.caption || '',
            caption_color: qrLink?.design?.caption_color || '#ffffff',
        },
        validate: {
            name: (value) => value.trim() ? null : t`Please give this QR code a name`,
        },
    });

    const type = form.values.destination_type;
    const previewUrl = qrLink?.public_url || `${window.location.origin}/q/preview`;
    const previewTitle = form.values.title.trim()
        || eventOptions.find(option => option.value === form.values.event_id)?.label
        || organizerName;

    const mergedBackgrounds = useMemo(() => {
        const all = [...backgrounds];
        const current = form.values.background_image;
        if (current && !all.some(background => background.id === current.id)) {
            all.unshift(current);
        }
        return all;
    }, [backgrounds, form.values.background_image]);

    const templateOptions: { value: QrPosterTemplate, label: string, icon?: JSX.Element, thumb: CSSProperties }[] = [
        {value: 'none', label: t`QR only`, icon: <IconQrcode size={22}/>, thumb: {background: 'var(--mantine-color-gray-1)'}},
        {value: 'story', label: t`Story`, thumb: {background: `linear-gradient(${form.values.foreground}, #000)`}},
        {value: 'poster', label: t`A4 poster`, thumb: {background: `linear-gradient(${form.values.foreground} 0 30%, #fff 30%)`, border: '1px solid var(--mantine-color-gray-3)'}},
        {value: 'square', label: t`Square`, thumb: {background: form.values.foreground, height: 44}},
        {
            value: 'custom',
            label: t`My image`,
            icon: mergedBackgrounds.length ? undefined : <IconPhoto size={22}/>,
            thumb: mergedBackgrounds.length
                ? {backgroundImage: `url(${(form.values.background_image || mergedBackgrounds[0]).url})`}
                : {background: 'var(--mantine-color-gray-1)'},
        },
    ];
    const isPending = createMutation.isPending || updateMutation.isPending;

    const buildDesign = (values: FormValues): QrLinkDesign => ({
        foreground: values.foreground,
        background: values.background,
        template: values.template,
        ...(values.template === 'custom' && values.background_image ? {
            background_image_id: values.background_image.id,
            background_image_url: values.background_image.url,
            background_width: values.background_image.width,
            background_height: values.background_image.height,
            qr_position: values.qr_position,
            qr_scale: values.qr_scale,
        } : {}),
        ...(values.template !== 'none' && values.caption.trim() ? {caption: values.caption.trim()} : {}),
        ...(values.template === 'custom' ? {caption_color: values.caption_color} : {}),
    });

    const chooseTemplate = (template: QrPosterTemplate) => {
        form.setFieldValue('template', template);
        if (template !== 'none' && !form.values.caption) {
            form.setFieldValue('caption', t`Scan me`);
        }
        if (template === 'custom' && !form.values.background_image && backgrounds.length) {
            form.setFieldValue('background_image', backgrounds[0]);
        }
    };

    const uploadBackground = (file: File | null) => {
        if (!file) return;
        setIsUploading(true);
        imageClient.uploadImage(file, 'QR_POSTER_BACKGROUND', organizerId)
            .then(({data}) => form.setFieldValue('background_image', {
                id: Number(data.id),
                url: data.url,
                width: data.width ?? undefined,
                height: data.height ?? undefined,
            }))
            .catch((error: any) => showError(error?.response?.data?.message || t`Unable to upload the image`))
            .finally(() => setIsUploading(false));
    };

    const submit = (values: FormValues) => {
        if (values.template === 'custom' && !values.background_image) {
            form.setFieldError('background_image', t`Please upload a background image`);
            return;
        }

        const payload: QrLinkRequest = {
            name: values.name.trim(),
            qr_campaign_id: values.qr_campaign_id ? Number(values.qr_campaign_id) : null,
            destination_type: values.destination_type,
            destination_url: values.destination_type === 'URL' ? values.destination_url.trim() : null,
            event_id: values.destination_type === 'URL' ? null : (values.event_id ? Number(values.event_id) : null),
            title: values.destination_type === 'LINK_PAGE' ? values.title.trim() || null : null,
            description: values.destination_type === 'LINK_PAGE' ? values.description.trim() || null : null,
            social_links: values.destination_type === 'LINK_PAGE'
                ? values.social_links.filter(link => link.url.trim()).map(link => ({...link, url: link.url.trim()}))
                : [],
            design: buildDesign(values),
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

                        {campaigns.length > 0 && (

                            <Select

                                {...form.getInputProps('qr_campaign_id')}

                                label={t`Campaign`}

                                data={campaigns.map(campaign => ({value: String(campaign.id), label: campaign.name}))}

                                placeholder={t`No campaign`}

                                clearable

                            />

                        )}


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

                        <div>
                            <Text size="sm" fw={500}>{t`Visual`}</Text>
                            <Text size="xs" c="dimmed" mb="xs">
                                {t`Download the QR code alone, or a ready-to-share visual with the QR code on it.`}
                            </Text>
                            <div className={classes.templateGrid}>
                                {templateOptions.map(option => (
                                    <button
                                        type="button"
                                        key={option.value}
                                        className={classes.templateOption}
                                        data-active={form.values.template === option.value}
                                        onClick={() => chooseTemplate(option.value)}
                                    >
                                        <span className={classes.templateThumb} style={option.thumb}>
                                            {option.icon}
                                        </span>
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {form.values.template === 'custom' && (
                            <Stack gap="sm">
                                <div>
                                    <Text size="sm" fw={500}>{t`Background image`}</Text>
                                    <Text size="xs" c="dimmed" mb="xs">
                                        {t`Your own design (JPG, PNG or WebP, up to 8 MB). Leave an empty area where the QR code goes.`}
                                    </Text>
                                    <div className={classes.backgroundChoices}>
                                        {mergedBackgrounds.map(background => (
                                            <button
                                                type="button"
                                                key={background.id}
                                                className={classes.backgroundChoice}
                                                data-active={form.values.background_image?.id === background.id}
                                                style={{backgroundImage: `url(${background.url})`}}
                                                onClick={() => form.setFieldValue('background_image', background)}
                                                aria-label={t`Use this background`}
                                            />
                                        ))}
                                        <FileButton onChange={uploadBackground} accept="image/png,image/jpeg,image/webp">
                                            {(props) => (
                                                <Button {...props} variant="light" size="xs" h={72}
                                                        loading={isUploading}
                                                        leftSection={<IconUpload size={14}/>}>
                                                    {t`Upload`}
                                                </Button>
                                            )}
                                        </FileButton>
                                    </div>
                                    {form.errors.background_image && (
                                        <Text size="xs" c="red" mt={4}>{form.errors.background_image}</Text>
                                    )}
                                </div>
                                <div>
                                    <Text size="xs" fw={500}>{t`QR code height`}</Text>
                                    <Slider {...form.getInputProps('qr_position')} min={10} max={90}
                                            label={(value) => `${value} %`}/>
                                </div>
                                <div>
                                    <Text size="xs" fw={500}>{t`QR code size`}</Text>
                                    <Slider {...form.getInputProps('qr_scale')} min={15} max={80}
                                            label={(value) => `${value} %`}/>
                                </div>
                            </Stack>
                        )}

                        {form.values.template !== 'none' && (
                            <Group grow align="flex-start">
                                <TextInput {...form.getInputProps('caption')} label={t`Text under the QR code`}
                                           maxLength={60} placeholder={t`Scan me`}/>
                                {form.values.template === 'custom' && (
                                    <ColorInput {...form.getInputProps('caption_color')} label={t`Text color`}
                                                format="hex"/>
                                )}
                            </Group>
                        )}

                        {type === 'URL' && (
                            <Alert color="yellow" variant="light">
                                {t`With a direct link, visitors leave immediately: your Meta pixel cannot record them. Choose the link page to build retargeting audiences.`}
                            </Alert>
                        )}
                    </Stack>

                    <Stack gap="md" align="center" className={classes.previewColumn}>
                        {form.values.template === 'none' ? (
                            <QrCodeImage
                                value={previewUrl}
                                design={{foreground: form.values.foreground, background: form.values.background}}
                                size={150}
                            />
                        ) : (
                            <QrPosterPreview
                                value={previewUrl}
                                design={buildDesign(form.values)}
                                title={previewTitle}
                                width={form.values.template === 'square' ? 190 : 160}
                            />
                        )}
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
