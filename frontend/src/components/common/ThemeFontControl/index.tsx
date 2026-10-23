import {Select, Text} from "@mantine/core";
import {t} from "@lingui/macro";
import {useEffect, useMemo} from "react";
import {
    buildHomepageFontStack,
    buildHomepageFontUrl,
    DEFAULT_HOMEPAGE_FONT,
    hasUsableOrganizerFont,
    HOMEPAGE_FONTS,
    ORGANIZER_FONT_VALUE,
} from "../../../constants/homepageFonts.ts";
import {OrganizerCustomFont} from "../../../types.ts";
import {ensureHomepageFontLoaded} from "../../../utilites/fontLoader.ts";
import classes from "./ThemeFontControl.module.scss";

interface ThemeFontControlProps {
    value: string | null | undefined;
    onChange: (fontFamily: string) => void;
    disabled?: boolean;
    organizerFont?: OrganizerCustomFont | null;
}

export const ThemeFontControl = ({value, onChange, disabled = false, organizerFont}: ThemeFontControlProps) => {
    const organizerFontAvailable = hasUsableOrganizerFont(organizerFont);
    const selected = value || DEFAULT_HOMEPAGE_FONT;

    const data = useMemo(
        () => {
            const fonts = HOMEPAGE_FONTS.map(font => ({value: font.value, label: font.label}));
            if (!organizerFontAvailable && selected !== ORGANIZER_FONT_VALUE) {
                return fonts;
            }
            const label = organizerFontAvailable
                ? `${t`Organizer font`} (${organizerFont?.family_name})`
                : t`Organizer font`;
            return [{value: ORGANIZER_FONT_VALUE, label}, ...fonts];
        },
        [organizerFontAvailable, organizerFont?.family_name, selected],
    );

    useEffect(() => {
        HOMEPAGE_FONTS.forEach(font => {
            if (font.value === DEFAULT_HOMEPAGE_FONT || typeof document === 'undefined') {
                return;
            }
            const id = `hi-font-preview-${font.bunnyFamily}`;
            if (document.getElementById(id)) {
                return;
            }
            const link = document.createElement('link');
            link.id = id;
            link.rel = 'stylesheet';
            link.href = buildHomepageFontUrl(font.value);
            document.head.appendChild(link);
        });
    }, []);

    useEffect(() => {
        ensureHomepageFontLoaded(selected, organizerFont);
        if (organizerFontAvailable) {
            ensureHomepageFontLoaded(ORGANIZER_FONT_VALUE, organizerFont);
        }
    }, [selected, organizerFont, organizerFontAvailable]);

    const handleChange = (next: string | null) => {
        if (!next) {
            return;
        }
        onChange(next);
    };

    const renderOption = ({option}: {option: {value: string; label: string}}) => (
        <div className={classes.option} style={{fontFamily: buildHomepageFontStack(option.value, organizerFont)}}>
            <span className={classes.optionLabel}>{option.label}</span>
            <span className={classes.optionSample}>Aa 123</span>
        </div>
    );

    return (
        <div>
            <Select
                label={t`Font Family`}
                description={t`Choose a typeface that matches your brand. Fonts are self-hosted via Bunny Fonts.`}
                size="sm"
                value={selected}
                onChange={handleChange}
                data={data}
                disabled={disabled}
                searchable
                allowDeselect={false}
                nothingFoundMessage={t`No matching fonts`}
                renderOption={renderOption}
                styles={{
                    input: {fontFamily: buildHomepageFontStack(selected, organizerFont)},
                }}
            />
            <Text size="xs" c="dimmed" mt={6} style={{fontFamily: buildHomepageFontStack(selected, organizerFont)}}>
                {t`The quick brown fox jumps over the lazy dog.`}
            </Text>
            {selected === ORGANIZER_FONT_VALUE && !organizerFontAvailable && (
                <Text size="xs" c="red" mt={4}>
                    {t`The organizer font is no longer available. Upload at least the Regular (400) file or choose another font.`}
                </Text>
            )}
        </div>
    );
};

export default ThemeFontControl;
