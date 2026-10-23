import {t} from "@lingui/macro";
import {useParams} from "react-router";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {ImageUploadDropzone} from "../../../../../common/ImageUploadDropzone";
import {GET_EVENT_IMAGES_QUERY_KEY, useGetEventImages} from "../../../../../../queries/useGetEventImages.ts";
import {queryClient} from "../../../../../../utilites/queryClient.ts";

export const EmailLogoSettings = () => {
    const {eventId} = useParams();
    const eventImagesQuery = useGetEventImages(eventId);
    const emailLogo = eventImagesQuery.data?.find((image) => image.type === 'EVENT_EMAIL_LOGO');
    const refreshImages = () => queryClient.invalidateQueries({queryKey: [GET_EVENT_IMAGES_QUERY_KEY, eventId]});

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Email logo for this event (light background)`}
                description={t`Shown at the top of every email sent to this event's participants. If empty, the organizer's email logo is used.`}
            />
            {eventImagesQuery.isFetched && (
                <ImageUploadDropzone
                    imageType="EVENT_EMAIL_LOGO"
                    entityId={eventId!}
                    onUploadSuccess={refreshImages}
                    onDeleteSuccess={refreshImages}
                    existingImageData={{url: emailLogo?.url, id: emailLogo?.id}}
                    helpText={t`Prefer a dark logo on a transparent background (PNG).`}
                />
            )}
        </Card>
    );
};
