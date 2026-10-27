import {useEffect, useRef} from "react";
import {t} from "@lingui/macro";
import {ActionIcon, Group, Progress, Text} from "@mantine/core";
import {Dropzone, FileRejection} from "@mantine/dropzone";
import {IconAlertTriangle, IconCheck, IconPhotoPlus, IconTrash, IconVideo} from "@tabler/icons-react";
import {IdParam, SocialMediaFile, SocialPostFormat} from "../../../../types.ts";
import {socialClient} from "../../../../api/social.client.ts";
import classes from "./Publications.module.scss";

export interface UploadEntry {
    localId: string;
    file: File;
    previewUrl: string;
    progress: number;
    status: 'uploading' | 'ready' | 'error';
    error?: string;
    media?: SocialMediaFile;
}

const IMAGE_TYPES = ['image/jpeg', 'image/png'];
const VIDEO_TYPES = ['video/mp4', 'video/quicktime'];
const MAX_IMAGE_BYTES = 8 * 1024 * 1024;
const MAX_VIDEO_BYTES = 100 * 1024 * 1024;

const acceptedTypes = (format: SocialPostFormat): string[] => {
    switch (format) {
        case 'REEL':
            return VIDEO_TYPES;
        case 'STORY':
            return [...IMAGE_TYPES, ...VIDEO_TYPES];
        default:
            return IMAGE_TYPES;
    }
};

export const maxFiles = (format: SocialPostFormat): number => format === 'CAROUSEL' ? 10 : 1;

interface MediaUploaderProps {
    organizerId: IdParam;
    format: SocialPostFormat;
    uploads: UploadEntry[];
    onChange: (update: (uploads: UploadEntry[]) => UploadEntry[]) => void;
}

export const MediaUploader = ({organizerId, format, uploads, onChange}: MediaUploaderProps) => {
    const previews = useRef<string[]>([]);

    useEffect(() => () => previews.current.forEach(url => URL.revokeObjectURL(url)), []);

    const update = (localId: string, patch: Partial<UploadEntry>) =>
        onChange(list => list.map(entry => entry.localId === localId ? {...entry, ...patch} : entry));

    const startUpload = (file: File) => {
        const localId = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
        const previewUrl = URL.createObjectURL(file);
        previews.current.push(previewUrl);

        onChange(list => [...list, {localId, file, previewUrl, progress: 0, status: 'uploading'}]);

        socialClient.uploadMedia(organizerId, file, format, (ratio) => update(localId, {progress: Math.round(ratio * 100)}))
            .then(media => update(localId, {status: 'ready', progress: 100, media}))
            .catch((error: any) => update(localId, {
                status: 'error',
                error: error?.response?.data?.errors?.file?.[0] ?? error?.response?.data?.message ?? t`The import failed. Please try again.`,
            }));
    };

    const onDrop = (files: File[]) => {
        const room = maxFiles(format) - uploads.length;
        files.slice(0, Math.max(0, room)).forEach(startUpload);
    };

    const onReject = (rejections: FileRejection[]) => {
        rejections.forEach(rejection => onChange(list => [...list, {
            localId: `${Date.now()}-${rejection.file.name}`,
            file: rejection.file,
            previewUrl: '',
            progress: 0,
            status: 'error',
            error: rejection.file.size > MAX_VIDEO_BYTES
                ? t`This file is too large.`
                : t`Only JPG and PNG images and MP4 and MOV videos can be imported.`,
        }]));
    };

    const canAddMore = uploads.length < maxFiles(format);
    const accepted = acceptedTypes(format);
    const allowsVideo = accepted.some(type => type.startsWith('video/'));

    return (
        <div>
            {uploads.length > 0 && (
                <div className={classes.uploadGrid}>
                    {uploads.map(entry => (
                        <div key={entry.localId} className={classes.uploadTile}>
                            {entry.previewUrl && (entry.file.type.startsWith('video/')
                                ? <video src={entry.previewUrl} muted playsInline className={classes.uploadThumb}/>
                                : <img src={entry.previewUrl} alt="" className={classes.uploadThumb}/>)}
                            {entry.status === 'uploading' && (
                                <div className={classes.uploadOverlay}>
                                    <Progress value={entry.progress} size="sm" w="80%" animated/>
                                    <Text size="xs" c="white" mt={4}>{entry.progress} %</Text>
                                </div>
                            )}
                            {entry.status === 'error' && (
                                <div className={classes.uploadError}>
                                    <IconAlertTriangle size={18}/>
                                    <Text size="xs">{entry.error}</Text>
                                </div>
                            )}
                            {entry.status === 'ready' && (
                                <span className={classes.uploadReady}><IconCheck size={14}/></span>
                            )}
                            <ActionIcon
                                className={classes.uploadRemove}
                                size="sm"
                                radius="xl"
                                variant="filled"
                                color="dark"
                                onClick={() => onChange(list => list.filter(item => item.localId !== entry.localId))}
                                aria-label={t`Remove`}
                            >
                                <IconTrash size={14}/>
                            </ActionIcon>
                        </div>
                    ))}
                </div>
            )}

            {canAddMore && (
                <Dropzone
                    onDrop={onDrop}
                    onReject={onReject}
                    accept={accepted}
                    maxSize={allowsVideo ? MAX_VIDEO_BYTES : MAX_IMAGE_BYTES}
                    multiple={maxFiles(format) > 1}
                    className={classes.dropzone}
                >
                    <Group justify="center" gap="sm" style={{pointerEvents: 'none'}}>
                        {allowsVideo ? <IconVideo size={28} stroke={1.5}/> : <IconPhotoPlus size={28} stroke={1.5}/>}
                        <div>
                            <Text size="sm" fw={500}>
                                {maxFiles(format) > 1 ? t`Drop your photos here or choose them` : t`Drop your file here or choose it`}
                            </Text>
                            <Text size="xs" c="dimmed">
                                {format === 'REEL'
                                    ? t`Vertical 9:16 video, 3 to 90 seconds, MP4 or MOV, up to 100 MB`
                                    : format === 'STORY'
                                        ? t`Photo or vertical video up to 60 seconds`
                                        : format === 'CAROUSEL'
                                            ? t`2 to 10 JPG photos, up to 8 MB each`
                                            : t`JPG photo (PNG for Facebook only), up to 8 MB`}
                            </Text>
                        </div>
                    </Group>
                </Dropzone>
            )}
        </div>
    );
};
