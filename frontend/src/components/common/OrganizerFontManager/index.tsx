import {t} from "@lingui/macro";
import {useEffect, useMemo, useState} from "react";
import {ActionIcon, Badge, Button, FileButton, Group, Stack, Text, TextInput} from "@mantine/core";
import {IconTrash, IconUpload} from "@tabler/icons-react";
import {IdParam, OrganizerFont} from "../../../types.ts";
import {toOrganizerCustomFont, useGetOrganizerFonts} from "../../../queries/useGetOrganizerFonts.ts";
import {
    useDeleteOrganizerFont,
    useUpdateOrganizerFontFamily,
    useUploadOrganizerFont,
} from "../../../mutations/useOrganizerFontMutations.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../utilites/confirmationDialog.tsx";
import {ensureHomepageFontLoaded} from "../../../utilites/fontLoader.ts";
import {
    hasUsableOrganizerFont,
    ORGANIZER_FONT_VALUE,
    sanitizeFontFamilyName,
} from "../../../constants/homepageFonts.ts";
import classes from "./OrganizerFontManager.module.scss";

const MAX_FONT_FILE_BYTES = 2 * 1024 * 1024;
const ACCEPTED_EXTENSIONS = ['woff2', 'woff', 'ttf', 'otf'];
const FAMILY_NAME_PATTERN = /^[\p{L}\p{N}][\p{L}\p{N} _.-]{0,99}$/u;

const fontErrorMessage = (error: any, fallback: string): string => error?.response?.data?.errors?.font_file?.[0]
    || error?.response?.data?.errors?.family_name?.[0]
    || error?.response?.data?.errors?.weight?.[0]
    || error?.response?.data?.message
    || fallback;

const formatSize = (bytes: number): string => {
    const kilobytes = Math.max(1, Math.round(bytes / 1024));
    return t`${kilobytes} KB`;
};

interface OrganizerFontManagerProps {
    organizerId: IdParam;
}

export const OrganizerFontManager = ({organizerId}: OrganizerFontManagerProps) => {
    const fontsQuery = useGetOrganizerFonts(organizerId);
    const uploadMutation = useUploadOrganizerFont();
    const renameMutation = useUpdateOrganizerFontFamily();
    const deleteMutation = useDeleteOrganizerFont();

    const fonts = fontsQuery.data || [];
    const savedFamilyName = fonts[0]?.family_name || '';
    const [familyName, setFamilyName] = useState(savedFamilyName);
    const [uploadingWeight, setUploadingWeight] = useState<number | null>(null);

    const weights = useMemo(() => [
        {weight: 300, label: t`Light (300)`, hint: t`Used for text`},
        {weight: 400, label: t`Regular (400)`, hint: t`Required`},
        {weight: 500, label: t`Medium (500)`, hint: ''},
        {weight: 700, label: t`Bold (700)`, hint: t`Used for headings`},
        {weight: 900, label: t`Black (900)`, hint: ''},
    ], []);

    useEffect(() => {
        setFamilyName(savedFamilyName);
    }, [savedFamilyName]);

    const customFont = toOrganizerCustomFont(fontsQuery.data);
    const isUsable = hasUsableOrganizerFont(customFont);
    const previewFamily = customFont ? `'${sanitizeFontFamilyName(customFont.family_name)}', 'Montserrat', sans-serif` : undefined;

    const customFontKey = JSON.stringify(customFont);

    useEffect(() => {
        if (customFont) {
            ensureHomepageFontLoaded(ORGANIZER_FONT_VALUE, customFont);
        }
    }, [customFontKey]);

    const trimmedFamilyName = familyName.trim();
    const familyNameIsValid = FAMILY_NAME_PATTERN.test(trimmedFamilyName);

    const handleUpload = (weight: number, file: File | null) => {
        if (!file) {
            return;
        }
        if (!familyNameIsValid) {
            showError(t`Enter the font name first (letters, numbers, spaces, dots, hyphens and underscores).`);
            return;
        }
        const extension = file.name.split('.').pop()?.toLowerCase() || '';
        if (!ACCEPTED_EXTENSIONS.includes(extension)) {
            showError(t`Accepted formats: WOFF2, WOFF, TTF, OTF.`);
            return;
        }
        if (file.size > MAX_FONT_FILE_BYTES) {
            showError(t`The font file must not be larger than 2 MB.`);
            return;
        }

        setUploadingWeight(weight);
        uploadMutation.mutate({organizerId, file, weight, familyName: trimmedFamilyName}, {
            onSuccess: () => showSuccess(t`Font file saved`),
            onError: (error: any) => showError(fontErrorMessage(error, t`The font could not be saved. Please try again.`)),
            onSettled: () => setUploadingWeight(null),
        });
    };

    const handleRename = () => {
        if (!familyNameIsValid) {
            showError(t`Enter the font name first (letters, numbers, spaces, dots, hyphens and underscores).`);
            return;
        }
        renameMutation.mutate({organizerId, familyName: trimmedFamilyName}, {
            onSuccess: () => showSuccess(t`Font name saved`),
            onError: (error: any) => showError(fontErrorMessage(error, t`Something went wrong. Please try again.`)),
        });
    };

    const handleDelete = (font: OrganizerFont) => {
        const message = font.weight === 400
            ? t`Remove the Regular file? Pages that use the organizer font will show the fallback font until a new Regular file is uploaded.`
            : t`Remove this font file?`;

        confirmationDialog(message, () => {
            deleteMutation.mutate({organizerId, fontId: font.id}, {
                onSuccess: () => showSuccess(t`Font file removed`),
                onError: () => showError(t`Something went wrong. Please try again.`),
            });
        });
    };

    return (
        <Stack gap="md">
            <Text size="sm" c="dimmed">
                {t`Upload your own typeface, one file per weight. Accepted formats: WOFF2, WOFF, TTF, OTF, 2 MB maximum per file. The Regular file is required. Make sure your licence allows use on a website.`}
            </Text>

            <Group align="flex-end" gap="xs" wrap="nowrap">
                <TextInput
                    className={classes.familyInput}
                    label={t`Font name`}
                    placeholder="franceTV Brown"
                    value={familyName}
                    maxLength={100}
                    onChange={(event) => setFamilyName(event.currentTarget.value)}
                    error={trimmedFamilyName !== '' && !familyNameIsValid
                        ? t`Letters, numbers, spaces, dots, hyphens and underscores only.`
                        : undefined}
                />
                {fonts.length > 0 && trimmedFamilyName !== savedFamilyName && (
                    <Button variant="light" onClick={handleRename} loading={renameMutation.isPending}>
                        {t`Save`}
                    </Button>
                )}
            </Group>

            <div className={classes.weights}>
                {weights.map(({weight, label, hint}) => {
                    const font = fonts.find(item => item.weight === weight);

                    return (
                        <div key={weight} className={classes.weightRow}>
                            <div className={classes.weightInfo}>
                                <Group gap={6}>
                                    <Text size="sm" fw={500}>{label}</Text>
                                    {hint && <Badge size="xs" variant="light" color={weight === 400 ? 'red' : 'gray'}>{hint}</Badge>}
                                </Group>
                                {font ? (
                                    <>
                                        <Text
                                            className={classes.sample}
                                            style={{fontFamily: previewFamily, fontWeight: weight}}
                                        >
                                            Aa Bb Cc 123
                                        </Text>
                                        <Text size="xs" c="dimmed" className={classes.fileName}>
                                            {font.file_name} ({font.format.toUpperCase()}, {formatSize(font.size)})
                                        </Text>
                                    </>
                                ) : (
                                    <Text size="xs" c="dimmed">{t`No file`}</Text>
                                )}
                            </div>
                            <Group gap={4} wrap="nowrap">
                                <FileButton
                                    onChange={(file) => handleUpload(weight, file)}
                                    accept=".woff2,.woff,.ttf,.otf"
                                >
                                    {(props) => (
                                        <Button
                                            {...props}
                                            size="xs"
                                            variant={font ? 'default' : 'light'}
                                            leftSection={<IconUpload size={14}/>}
                                            loading={uploadMutation.isPending && uploadingWeight === weight}
                                            disabled={uploadMutation.isPending && uploadingWeight !== weight}
                                        >
                                            {font ? t`Replace` : t`Upload`}
                                        </Button>
                                    )}
                                </FileButton>
                                {font && (
                                    <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        onClick={() => handleDelete(font)}
                                        aria-label={t`Remove`}
                                        loading={deleteMutation.isPending && deleteMutation.variables?.fontId === font.id}
                                    >
                                        <IconTrash size={16}/>
                                    </ActionIcon>
                                )}
                            </Group>
                        </div>
                    );
                })}
            </div>

            <Text size="xs" c={isUsable ? 'green' : 'dimmed'}>
                {isUsable
                    ? t`The organizer font can now be chosen in Typography, for the organizer page and for each event.`
                    : t`Upload the Regular file to be able to choose this font.`}
            </Text>
        </Stack>
    );
};

export default OrganizerFontManager;
