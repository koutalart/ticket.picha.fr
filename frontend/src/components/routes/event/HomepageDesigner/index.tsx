import {useEffect, useRef, useState} from "react";
import classes from './HomepageDesigner.module.scss';
import {useParams} from "react-router";
import {useGetEventSettings} from "../../../../queries/useGetEventSettings.ts";
import {useUpdateEventSettings} from "../../../../mutations/useUpdateEventSettings.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {EventSettings, HomepageThemeSettings, IdParam} from "../../../../types.ts";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {t} from "@lingui/macro";
import {useForm} from "@mantine/form";
import {Button, FileButton, Group, TextInput, Textarea, Accordion, Stack, Text} from "@mantine/core";
import {IconColorPicker, IconHelp, IconLayoutNavbar, IconMaximize, IconPhoto, IconPalette, IconTrash, IconTypography, IconVideo} from "@tabler/icons-react";
import {Tooltip} from "../../../common/Tooltip";
import {CustomSelect} from "../../../common/CustomSelect";
import {GET_EVENT_IMAGES_QUERY_KEY, useGetEventImages} from "../../../../queries/useGetEventImages.ts";
import {eventPreviewPath} from "../../../../utilites/urlHelper.ts";
import {LoadingMask} from "../../../common/LoadingMask";
import {ImageUploadDropzone} from "../../../common/ImageUploadDropzone";
import {queryClient} from "../../../../utilites/queryClient.ts";
import {GET_EVENT_PUBLIC_QUERY_KEY} from "../../../../queries/useGetEventPublic.ts";
import {ThemeColorControls} from "../../../common/ThemeColorControls";
import {ThemeFontControl} from "../../../common/ThemeFontControl";
import {validateThemeSettings} from "../../../../utilites/themeUtils.ts";
import {DEFAULT_HOMEPAGE_FONT} from "../../../../constants/homepageFonts.ts";
import {useUploadEventCoverVideo} from "../../../../mutations/useUploadEventCoverVideo.ts";
import {useDeleteEventImage} from "../../../../mutations/useDeleteEventImage.ts";
import {confirmationDialog} from "../../../../utilites/confirmationDialog.tsx";
import {useGetEvent} from "../../../../queries/useGetEvent.ts";
import {toOrganizerCustomFont, useGetOrganizerFonts} from "../../../../queries/useGetOrganizerFonts.ts";

const MAX_COVER_VIDEO_BYTES = 15 * 1024 * 1024;

interface FormValues {
    homepage_theme_settings: Partial<HomepageThemeSettings>;
    continue_button_text: string;
}

const HomepageDesigner = () => {
    const {eventId} = useParams();
    const eventSettingsQuery = useGetEventSettings(eventId);
    const eventImagesQuery = useGetEventImages(eventId);
    const updateMutation = useUpdateEventSettings();
    const eventQuery = useGetEvent(eventId);
    const organizerFontsQuery = useGetOrganizerFonts(eventQuery.data?.organizer_id);
    const organizerFont = toOrganizerCustomFont(organizerFontsQuery.data);

    const iframeRef = useRef<HTMLIFrameElement>(null);
    const lastSentSettings = useRef<string | null>(null);

    const [iframeSrc, setIframeSrc] = useState<string | null>(null);
    const [iframeLoaded, setIframeLoaded] = useState(false);
    const [lastCoverId, setLastCoverId] = useState<IdParam | null>(null);
    const [accordionValue, setAccordionValue] = useState<string[]>(['images', 'colors', 'typography', 'button']);

    const existingCover = eventImagesQuery.data?.find((image) => image.type === 'EVENT_COVER');
    const existingCoverVideo = eventImagesQuery.data?.find((image) => image.type === 'EVENT_COVER_VIDEO');
    const existingCoverVideoMobile = eventImagesQuery.data?.find((image) => image.type === 'EVENT_COVER_VIDEO_MOBILE');
    const existingFlyer = eventImagesQuery.data?.find((image) => image.type === 'EVENT_FLYER');
    const existingHeroPartnerLogo = eventImagesQuery.data?.find((image) => image.type === 'EVENT_HERO_PARTNER_LOGO');
    const existingHeroSignatureLogo = eventImagesQuery.data?.find((image) => image.type === 'EVENT_HERO_SIGNATURE_LOGO');
    const existingHeroBrandLogo = eventImagesQuery.data?.find((image) => image.type === 'EVENT_HERO_BRAND_LOGO');
    const uploadCoverVideo = useUploadEventCoverVideo();
    const deleteEventImage = useDeleteEventImage();

    const form = useForm<FormValues>({
        initialValues: {
            homepage_theme_settings: {
                accent: '#8b5cf6',
                background: '#f5f3ff',
                mode: 'light',
                background_type: 'COLOR',
                font_family: DEFAULT_HOMEPAGE_FONT,
            },
            continue_button_text: '',
        }
    });

    const formErrorHandle = useFormErrorResponseHandler();

    useEffect(() => {
        if (eventSettingsQuery?.isFetched && eventSettingsQuery?.data) {
            const settings = eventSettingsQuery.data;
            const themeSettings = validateThemeSettings(settings.homepage_theme_settings);

            form.setValues({
                homepage_theme_settings: themeSettings,
                continue_button_text: settings.continue_button_text,
            });
        }
    }, [eventSettingsQuery.isFetched]);

    useEffect(() => {
        if (eventSettingsQuery.isFetched && eventImagesQuery.isFetched && !iframeSrc) {
            setIframeSrc(eventPreviewPath(eventId));
        }
    }, [eventSettingsQuery.isFetched, eventImagesQuery.isFetched]);

    useEffect(() => {
        if (existingCover?.id !== lastCoverId && iframeSrc) {
            setLastCoverId(existingCover?.id);
            setIframeSrc(eventPreviewPath(eventId) + `?cover_image_id=${existingCover?.id}`);
            setIframeLoaded(false);
        }
    }, [existingCover?.id]);

    const handleSubmit = (values: FormValues) => {
        const validatedTheme = validateThemeSettings(values.homepage_theme_settings);

        const eventSettings: Partial<EventSettings> = {
            homepage_theme_settings: validatedTheme,
            continue_button_text: values.continue_button_text,
            // Also update legacy fields for backward compatibility during transition
            homepage_primary_color: validatedTheme.accent,
            homepage_body_background_color: validatedTheme.background,
            homepage_background_type: validatedTheme.background_type,
        };

        updateMutation.mutate(
            {eventSettings, eventId: eventId},
            {
                onSuccess: () => {
                    showSuccess(t`Successfully Updated Homepage Design`);
                },
                onError: (error) => {
                    formErrorHandle(form, error);
                },
            }
        );
    };

    const handleImageChange = () => {
        queryClient.invalidateQueries({
            queryKey: [GET_EVENT_IMAGES_QUERY_KEY, eventId]
        });
        queryClient.invalidateQueries({
            queryKey: [GET_EVENT_PUBLIC_QUERY_KEY, eventId]
        });
    };

    const handleCoverVideo = (file: File | null, mobile = false) => {
        if (!file) {
            return;
        }
        if (!['video/mp4', 'video/webm'].includes(file.type)) {
            showError(t`The video must be an MP4 or WebM file.`);
            return;
        }
        if (file.size > MAX_COVER_VIDEO_BYTES) {
            showError(t`The video must not be larger than 15 MB.`);
            return;
        }
        uploadCoverVideo.mutate({eventId, video: file, mobile}, {
            onSuccess: () => showSuccess(mobile ? t`Mobile video saved` : t`Cover video saved`),
            onError: (error: any) => showError(error?.response?.data?.errors?.video?.[0]
                || error?.response?.data?.message
                || t`The video could not be saved. Please try again.`),
        });
    };

    const removeCoverVideo = () => {
        if (!existingCoverVideo?.id) {
            return;
        }
        confirmationDialog(t`Remove the cover video? The cover image will be shown instead.`, () => {
            deleteEventImage.mutate({eventId, imageId: existingCoverVideo.id}, {
                onSuccess: () => showSuccess(t`Cover video removed`),
                onError: () => showError(t`Something went wrong. Please try again.`),
            });
        });
    };

    const removeCoverVideoMobile = () => {
        if (!existingCoverVideoMobile?.id) {
            return;
        }
        confirmationDialog(t`Remove the mobile video? Phones will play the main cover video instead.`, () => {
            deleteEventImage.mutate({eventId, imageId: existingCoverVideoMobile.id}, {
                onSuccess: () => showSuccess(t`Mobile video removed`),
                onError: () => showError(t`Something went wrong. Please try again.`),
            });
        });
    };

    const isUploadingCoverVideo = (mobile: boolean) =>
        uploadCoverVideo.isPending && !!uploadCoverVideo.variables?.mobile === mobile;
    const isDeletingImage = (imageId?: IdParam) =>
        deleteEventImage.isPending && deleteEventImage.variables?.imageId === imageId;

    const sendSettingsToIframe = () => {
        if (iframeRef.current?.contentWindow && iframeLoaded) {
            const themeSettings = validateThemeSettings(form.values.homepage_theme_settings);

            const settingsToSend = {
                homepage_theme_settings: themeSettings,
                continue_button_text: form.values.continue_button_text,
            };

            const settingsJson = JSON.stringify(settingsToSend);
            if (settingsJson !== lastSentSettings.current) {
                iframeRef.current.contentWindow.postMessage(
                    {type: "UPDATE_SETTINGS", settings: settingsToSend},
                    "*"
                );
                lastSentSettings.current = settingsJson;
            }
        }
    };

    useEffect(() => {
        sendSettingsToIframe();
    }, [iframeLoaded, form.values]);

    const handleThemeChange = (themeSettings: Partial<HomepageThemeSettings>) => {
        form.setFieldValue('homepage_theme_settings', themeSettings);
    };

    const handleBackgroundTypeChange = (backgroundType: string | string[]) => {
        const value = Array.isArray(backgroundType) ? backgroundType[0] : backgroundType;
        form.setFieldValue('homepage_theme_settings', {
            ...form.values.homepage_theme_settings,
            background_type: value as 'COLOR' | 'MIRROR_COVER_IMAGE',
        });
    };

    const handleHeroLayoutChange = (heroLayout: string | string[]) => {
        const value = Array.isArray(heroLayout) ? heroLayout[0] : heroLayout;
        form.setFieldValue('homepage_theme_settings', {
            ...form.values.homepage_theme_settings,
            hero_layout: value as 'CARD' | 'FULLSCREEN',
        });
    };

    return (
        <div className={classes.container}>
            <div className={classes.sidebar}>
                <div className={classes.sticky}>
                    <div className={classes.header}>
                        <h2>{t`Homepage Design`}</h2>
                        <Text c="dimmed" size="sm">{t`Customize the layout, colors, and branding of your event homepage.`}</Text>
                    </div>

                    <Accordion
                        multiple
                        value={accordionValue}
                        onChange={setAccordionValue}
                        variant="contained"
                        className={classes.accordion}
                    >
                        <Accordion.Item value="images" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconPhoto size={20} />}>
                                <Text fw={500}>{t`Images`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <Stack gap="lg">
                                    <div>
                                        <Group justify={'space-between'} mb="xs">
                                            <Text fw={500} size="sm">{t`Cover Image`}</Text>
                                            <Tooltip
                                                label={t`We recommend dimensions of 1950px by 650px, a ratio of 3:1, and a maximum file size of 5MB`}>
                                                <IconHelp size={16} style={{ color: 'var(--mantine-color-gray-6)' }}/>
                                            </Tooltip>
                                        </Group>
                                        <ImageUploadDropzone
                                            imageType="EVENT_COVER"
                                            entityId={eventId}
                                            onUploadSuccess={handleImageChange}
                                            onDeleteSuccess={handleImageChange}
                                            existingImageData={{
                                                url: existingCover?.url,
                                                id: existingCover?.id,
                                            }}
                                            helpText={t`Cover image will be displayed at the top of your event page`}
                                            displayMode="compact"
                                        />
                                    </div>

                                    <div>
                                        <Group justify={'space-between'} mb="xs">
                                            <Text fw={500} size="sm">{t`Cover video`}</Text>
                                            <Tooltip label={t`A short, silent loop (10 to 20 seconds) played at the top of the page. MP4 or WebM, 15 MB maximum. The cover image stays for thumbnails and sharing.`}>
                                                <IconHelp size={16} style={{color: 'var(--mantine-color-gray-6)'}}/>
                                            </Tooltip>
                                        </Group>
                                        {existingCoverVideo?.url && (
                                            <video className={classes.coverVideoPreview} src={existingCoverVideo.url}
                                                   muted loop playsInline autoPlay/>
                                        )}
                                        <Group gap="xs">
                                            <FileButton onChange={(file) => handleCoverVideo(file)} accept="video/mp4,video/webm">
                                                {(props) => (
                                                    <Button {...props} variant="light" leftSection={<IconVideo size={16}/>}
                                                            loading={isUploadingCoverVideo(false)}>
                                                        {existingCoverVideo ? t`Replace the video` : t`Add a video`}
                                                    </Button>
                                                )}
                                            </FileButton>
                                            {existingCoverVideo && (
                                                <Button variant="subtle" color="red" leftSection={<IconTrash size={16}/>}
                                                        loading={isDeletingImage(existingCoverVideo.id)} onClick={removeCoverVideo}>
                                                    {t`Remove`}
                                                </Button>
                                            )}
                                        </Group>
                                        <Text size="xs" c="dimmed" mt={6}>
                                            {t`Optional. Replaces the cover image at the top of the page, without sound.`}
                                        </Text>
                                    </div>

                                    {(existingCoverVideo || existingCoverVideoMobile) && (
                                        <div>
                                            <Text fw={500} size="sm" mb="xs">{t`Video for mobile (optional)`}</Text>
                                            {existingCoverVideoMobile?.url && (
                                                <video className={classes.coverVideoPreview} src={existingCoverVideoMobile.url}
                                                       muted loop playsInline autoPlay/>
                                            )}
                                            <Group gap="xs">
                                                <FileButton onChange={(file) => handleCoverVideo(file, true)}
                                                            accept="video/mp4,video/webm">
                                                    {(props) => (
                                                        <Button {...props} variant="light" leftSection={<IconVideo size={16}/>}
                                                                loading={isUploadingCoverVideo(true)}>
                                                            {existingCoverVideoMobile ? t`Replace the video` : t`Add a video`}
                                                        </Button>
                                                    )}
                                                </FileButton>
                                                {existingCoverVideoMobile && (
                                                    <Button variant="subtle" color="red" leftSection={<IconTrash size={16}/>}
                                                            loading={isDeletingImage(existingCoverVideoMobile.id)}
                                                            onClick={removeCoverVideoMobile}>
                                                        {t`Remove`}
                                                    </Button>
                                                )}
                                            </Group>
                                            <Text size="xs" c="dimmed" mt={6}>
                                                {t`Lighter version, for example 720p, used on phones.`}
                                            </Text>
                                        </div>
                                    )}

                                    <div>
                                        <Group justify={'space-between'} mb="xs">
                                            <Text fw={500} size="sm">{t`Event flyer`}</Text>
                                            <Tooltip label={t`Your poster in portrait format, shown on the page between Tickets and Organizer. It is separate from the cover image, which is still used for thumbnails and sharing.`}>
                                                <IconHelp size={16} style={{color: 'var(--mantine-color-gray-6)'}}/>
                                            </Tooltip>
                                        </Group>
                                        {existingCoverVideo ? (
                                            <ImageUploadDropzone
                                                imageType="EVENT_FLYER"
                                                entityId={eventId}
                                                onUploadSuccess={handleImageChange}
                                                onDeleteSuccess={handleImageChange}
                                                existingImageData={{
                                                    url: existingFlyer?.url,
                                                    id: existingFlyer?.id,
                                                }}
                                                helpText={t`Optional. Shown between the Tickets and Organizer sections.`}
                                                displayMode="compact"
                                            />
                                        ) : (
                                            <Text size="xs" c="dimmed">
                                                {t`Available once a cover video has been added.`}
                                            </Text>
                                        )}
                                    </div>

                                    {form.values.homepage_theme_settings.hero_layout === 'FULLSCREEN' && (
                                        <>
                                            <div>
                                                <Text fw={500} size="sm" mb="xs">{t`Header partner logo`}</Text>
                                                <ImageUploadDropzone
                                                    imageType="EVENT_HERO_PARTNER_LOGO"
                                                    entityId={eventId}
                                                    onUploadSuccess={handleImageChange}
                                                    onDeleteSuccess={handleImageChange}
                                                    existingImageData={{
                                                        url: existingHeroPartnerLogo?.url,
                                                        id: existingHeroPartnerLogo?.id,
                                                    }}
                                                    helpText={t`Shown at the top left of the full screen header. Use a PNG with a transparent background, light enough to read on the video.`}
                                                    displayMode="compact"
                                                />
                                            </div>
                                            <div>
                                                <Text fw={500} size="sm" mb="xs">{t`Header logo, light version`}</Text>
                                                <ImageUploadDropzone
                                                    imageType="EVENT_HERO_BRAND_LOGO"
                                                    entityId={eventId}
                                                    onUploadSuccess={handleImageChange}
                                                    onDeleteSuccess={handleImageChange}
                                                    existingImageData={{
                                                        url: existingHeroBrandLogo?.url,
                                                        id: existingHeroBrandLogo?.id,
                                                    }}
                                                    helpText={t`Shown at the top right, as is. Without it, your organizer logo is shown in white.`}
                                                    displayMode="compact"
                                                />
                                            </div>
                                            <div>
                                                <Text fw={500} size="sm" mb="xs">{t`Header signature logo`}</Text>
                                                <ImageUploadDropzone
                                                    imageType="EVENT_HERO_SIGNATURE_LOGO"
                                                    entityId={eventId}
                                                    onUploadSuccess={handleImageChange}
                                                    onDeleteSuccess={handleImageChange}
                                                    existingImageData={{
                                                        url: existingHeroSignatureLogo?.url,
                                                        id: existingHeroSignatureLogo?.id,
                                                    }}
                                                    helpText={t`Shown after the header signature, for example the producer's logo. PNG with a transparent background.`}
                                                    displayMode="compact"
                                                />
                                            </div>
                                        </>
                                    )}
                                </Stack>
                            </Accordion.Panel>
                        </Accordion.Item>

                        <Accordion.Item value="colors" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconPalette size={20} />}>
                                <Text fw={500}>{t`Theme & Colors`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <form onSubmit={form.onSubmit(handleSubmit)}>
                                    <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending} className={classes.fieldset}>
                                        <Stack gap="md">
                                            <CustomSelect
                                                optionList={[
                                                    {
                                                        icon: <IconLayoutNavbar/>,
                                                        label: t`Classic`,
                                                        value: 'CARD',
                                                        description: t`The cover is shown at the top of the event card`,
                                                    },
                                                    {
                                                        icon: <IconMaximize/>,
                                                        label: t`Full screen`,
                                                        value: 'FULLSCREEN',
                                                        description: t`The cover video or image fills the screen, with your logo in the center and a link to the tickets at the bottom`,
                                                        disabled: !existingCover && !existingCoverVideo,
                                                    },
                                                ]}
                                                label={t`Header layout`}
                                                name={'homepage_theme_settings.hero_layout'}
                                                value={form.values.homepage_theme_settings.hero_layout || 'CARD'}
                                                onChange={handleHeroLayoutChange}
                                            />

                                            {form.values.homepage_theme_settings.hero_layout === 'FULLSCREEN' && (
                                                <>
                                                    <Textarea
                                                        label={t`Header title`}
                                                        description={t`Shown in the center of the video. One line per row. Leave empty to use the event name.`}
                                                        autosize
                                                        minRows={3}
                                                        maxLength={120}
                                                        value={form.values.homepage_theme_settings.hero_title || ''}
                                                        onChange={(e) => form.setFieldValue('homepage_theme_settings', {
                                                            ...form.values.homepage_theme_settings,
                                                            hero_title: e.currentTarget.value,
                                                        })}
                                                    />
                                                    <TextInput
                                                        label={t`Header subtitle`}
                                                        description={t`Optional. A line under the title, for example a second event on the same evening.`}
                                                        maxLength={120}
                                                        value={form.values.homepage_theme_settings.hero_subtitle || ''}
                                                        onChange={(e) => form.setFieldValue('homepage_theme_settings', {
                                                            ...form.values.homepage_theme_settings,
                                                            hero_subtitle: e.currentTarget.value,
                                                        })}
                                                    />
                                                    <TextInput
                                                        label={t`Header signature`}
                                                        description={t`A short line under the title, for example the producer or a partner.`}
                                                        maxLength={80}
                                                        value={form.values.homepage_theme_settings.hero_tagline || ''}
                                                        onChange={(e) => form.setFieldValue('homepage_theme_settings', {
                                                            ...form.values.homepage_theme_settings,
                                                            hero_tagline: e.currentTarget.value,
                                                        })}
                                                    />
                                                </>
                                            )}

                                            <CustomSelect
                                                optionList={[
                                                    {
                                                        icon: <IconColorPicker/>,
                                                        label: t`Color`,
                                                        value: 'COLOR',
                                                        description: t`Choose a color for your background`,
                                                    },
                                                    {
                                                        icon: <IconPhoto/>,
                                                        label: t`Use cover image`,
                                                        value: 'MIRROR_COVER_IMAGE',
                                                        description: t`Use a blurred version of the cover image as the background`,
                                                        disabled: !existingCover,
                                                    },
                                                ]}
                                                label={t`Background Type`}
                                                name={'homepage_theme_settings.background_type'}
                                                value={form.values.homepage_theme_settings.background_type || 'COLOR'}
                                                onChange={handleBackgroundTypeChange}
                                            />

                                            <ThemeColorControls
                                                values={form.values.homepage_theme_settings}
                                                onChange={handleThemeChange}
                                                disabled={eventSettingsQuery.isLoading || updateMutation.isPending}
                                            />
                                        </Stack>
                                    </fieldset>
                                </form>
                            </Accordion.Panel>
                        </Accordion.Item>

                        <Accordion.Item value="typography" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconTypography size={20} />}>
                                <Text fw={500}>{t`Typography`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending} className={classes.fieldset}>
                                    <ThemeFontControl
                                        value={form.values.homepage_theme_settings.font_family}
                                        onChange={(fontFamily) => form.setFieldValue('homepage_theme_settings', {
                                            ...form.values.homepage_theme_settings,
                                            font_family: fontFamily,
                                        })}
                                        disabled={eventSettingsQuery.isLoading || updateMutation.isPending}
                                        organizerFont={organizerFont}
                                    />
                                </fieldset>
                            </Accordion.Panel>
                        </Accordion.Item>

                        <Accordion.Item value="button" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconTypography size={20} />}>
                                <Text fw={500}>{t`Button Text`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <form onSubmit={form.onSubmit(handleSubmit)}>
                                    <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending} className={classes.fieldset}>
                                        <Stack gap="md">
                                            <TextInput
                                                label={t`Continue Button Text`}
                                                description={t`Customize the text shown on the continue button`}
                                                placeholder={t`e.g., Get Tickets, Register Now`}
                                                size="sm"
                                                {...form.getInputProps('continue_button_text')}
                                            />
                                        </Stack>
                                    </fieldset>
                                </form>
                            </Accordion.Panel>
                        </Accordion.Item>
                    </Accordion>

                    <Button
                        loading={updateMutation.isPending}
                        type="submit"
                        fullWidth
                        mt="md"
                        onClick={() => form.onSubmit(handleSubmit)()}
                    >
                        {t`Save Changes`}
                    </Button>
                </div>
            </div>

            <div className={classes.previewContainer}>
                <h2>{t`Homepage Preview`}</h2>
                <div className={classes.iframeContainer}>
                    {iframeSrc ? (
                        <iframe
                            ref={iframeRef}
                            src={iframeSrc}
                            title="Event Preview"
                            onLoad={() => setIframeLoaded(true)}
                        />
                    ) : (
                        <LoadingMask/>
                    )}
                </div>
            </div>
        </div>
    );
};

export default HomepageDesigner;
