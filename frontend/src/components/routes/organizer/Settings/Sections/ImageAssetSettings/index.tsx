import {t} from "@lingui/macro";
import {useParams} from "react-router";
import {ImageUploadDropzone} from "../../../../../common/ImageUploadDropzone";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {useGetOrganizer} from "../../../../../../queries/useGetOrganizer.ts";
import {Button, FileButton, Group, Text} from "@mantine/core";
import {IconTrash, IconVideo} from "@tabler/icons-react";
import {useUploadOrganizerCoverVideo} from "../../../../../../mutations/useUploadOrganizerCoverVideo.ts";
import {useDeleteImage} from "../../../../../../mutations/useDeleteImage.ts";
import {showError, showSuccess} from "../../../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../../../utilites/confirmationDialog.tsx";
import classes from "./ImageAssetSettings.module.scss";
import {IdParam} from "../../../../../../types.ts";

const MAX_COVER_VIDEO_BYTES = 15 * 1024 * 1024;

const Settings = () => {
    const {organizerId} = useParams();
    const organizerQuery = useGetOrganizer(organizerId);
    const {data: organizer} = organizerQuery;
    const organizerLogo = organizer?.images?.find((image) => image.type === 'ORGANIZER_LOGO');
    const organizerEmailLogo = organizer?.images?.find((image) => image.type === 'ORGANIZER_EMAIL_LOGO');
    const organizerCover = organizer?.images?.find((image) => image.type === 'ORGANIZER_COVER');
    const organizerCoverVideo = organizer?.images?.find((image) => image.type === 'ORGANIZER_COVER_VIDEO');
    const organizerCoverVideoMobile = organizer?.images?.find((image) => image.type === 'ORGANIZER_COVER_VIDEO_MOBILE');
    const uploadCoverVideo = useUploadOrganizerCoverVideo();
    const deleteImage = useDeleteImage();

    const handleImageChange = () => {
        organizerQuery.refetch();
    };

    const handleCoverVideo = (file: File | null, mobile = false) => {
        if (!file || !organizerId) {
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
        uploadCoverVideo.mutate({organizerId, video: file, mobile}, {
            onSuccess: () => showSuccess(mobile ? t`Mobile video saved` : t`Cover video saved`),
            onError: (error: any) => showError(error?.response?.data?.errors?.video?.[0]
                || error?.response?.data?.message
                || t`The video could not be saved. Please try again.`),
        });
    };

    const removeCoverVideo = () => {
        if (!organizerCoverVideo?.id) {
            return;
        }
        confirmationDialog(t`Remove the cover video? The cover image will be shown instead.`, () => {
            deleteImage.mutate({imageId: organizerCoverVideo.id!}, {
                onSuccess: () => {
                    showSuccess(t`Cover video removed`);
                    handleImageChange();
                },
                onError: () => showError(t`Something went wrong. Please try again.`),
            });
        });
    };

    const removeCoverVideoMobile = () => {
        if (!organizerCoverVideoMobile?.id) {
            return;
        }
        confirmationDialog(t`Remove the mobile video? Phones will play the main cover video instead.`, () => {
            deleteImage.mutate({imageId: organizerCoverVideoMobile.id!}, {
                onSuccess: () => {
                    showSuccess(t`Mobile video removed`);
                    handleImageChange();
                },
                onError: () => showError(t`Something went wrong. Please try again.`),
            });
        });
    };

    const isUploadingCoverVideo = (mobile: boolean) =>
        uploadCoverVideo.isPending && !!uploadCoverVideo.variables?.mobile === mobile;
    const isDeletingImage = (imageId?: IdParam) =>
        deleteImage.isPending && deleteImage.variables?.imageId === imageId;

    return (
        <>
            <Card>
                <HeadingWithDescription
                    heading={t`Logo & Cover`}
                    description={t`Logo and cover image for your organizer`}
                />
                <Text size="sm" c="dimmed" mb="md">
                    {t`Your pages pick the right logo for each background. If you only add one, it is used everywhere.`}
                </Text>
                <h2>{t`Logo for dark or coloured backgrounds`}</h2>
                <ImageUploadDropzone
                    helpText={t`Used on full screen videos, coloured footers and dark pages. A white or light logo works best.`}
                    imageType={'ORGANIZER_LOGO'}
                    entityId={organizerId}
                    onUploadSuccess={handleImageChange}
                    existingImageData={{
                        url: organizerLogo?.url,
                        id: organizerLogo?.id,
                    }}
                />
                <h2>{t`Logo for light backgrounds (emails and website)`}</h2>
                <ImageUploadDropzone
                    helpText={t`Used in emails and on white areas of your pages, such as your organizer page and event cards. Prefer a dark logo on a transparent background (PNG).`}
                    imageType={'ORGANIZER_EMAIL_LOGO'}
                    entityId={organizerId}
                    onUploadSuccess={handleImageChange}
                    existingImageData={{
                        url: organizerEmailLogo?.url,
                        id: organizerEmailLogo?.id,
                    }}
                />
                <h2>{t`Cover`}</h2>
                <ImageUploadDropzone
                    helpText={t`Upload a cover image for your organizer`}
                    imageType={'ORGANIZER_COVER'}
                    entityId={organizerId}
                    onUploadSuccess={handleImageChange}
                    existingImageData={{
                        url: organizerCover?.url,
                        id: organizerCover?.id,
                    }}
                />
                <h2>{t`Cover video`}</h2>
                {organizerCoverVideo?.url && (
                    <video className={classes.coverVideoPreview} src={organizerCoverVideo.url}
                           muted loop playsInline autoPlay/>
                )}
                <Group gap="xs">
                    <FileButton onChange={(file) => handleCoverVideo(file)} accept="video/mp4,video/webm">
                        {(props) => (
                            <Button {...props} variant="light" leftSection={<IconVideo size={16}/>}
                                    loading={isUploadingCoverVideo(false)}>
                                {organizerCoverVideo ? t`Replace the video` : t`Add a video`}
                            </Button>
                        )}
                    </FileButton>
                    {organizerCoverVideo && (
                        <Button variant="subtle" color="red" leftSection={<IconTrash size={16}/>}
                                loading={isDeletingImage(organizerCoverVideo.id)} onClick={removeCoverVideo}>
                            {t`Remove`}
                        </Button>
                    )}
                </Group>
                <Text size="xs" c="dimmed" mt={6}>
                    {t`Optional. A short, silent loop shown at the top of the organizer page by templates that support it. The cover image stays for thumbnails and sharing.`}
                </Text>
                {(organizerCoverVideo || organizerCoverVideoMobile) && (
                    <>
                        <h2>{t`Video for mobile (optional)`}</h2>
                        {organizerCoverVideoMobile?.url && (
                            <video className={classes.coverVideoPreview} src={organizerCoverVideoMobile.url}
                                   muted loop playsInline autoPlay/>
                        )}
                        <Group gap="xs">
                            <FileButton onChange={(file) => handleCoverVideo(file, true)} accept="video/mp4,video/webm">
                                {(props) => (
                                    <Button {...props} variant="light" leftSection={<IconVideo size={16}/>}
                                            loading={isUploadingCoverVideo(true)}>
                                        {organizerCoverVideoMobile ? t`Replace the video` : t`Add a video`}
                                    </Button>
                                )}
                            </FileButton>
                            {organizerCoverVideoMobile && (
                                <Button variant="subtle" color="red" leftSection={<IconTrash size={16}/>}
                                        loading={isDeletingImage(organizerCoverVideoMobile.id)}
                                        onClick={removeCoverVideoMobile}>
                                    {t`Remove`}
                                </Button>
                            )}
                        </Group>
                        <Text size="xs" c="dimmed" mt={6}>
                            {t`Lighter version, for example 720p, used on phones.`}
                        </Text>
                    </>
                )}
            </Card>
        </>
    );
}

export default Settings;
