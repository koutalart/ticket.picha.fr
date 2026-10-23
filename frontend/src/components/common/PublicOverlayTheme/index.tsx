import {PropsWithChildren, useMemo, useState} from "react";
import {
    ButtonProps,
    defaultCssVariablesResolver,
    getCSSColorVariables,
    MantineTheme,
    MantineThemeOverride,
    MantineThemeProvider,
    mergeMantineTheme,
    useMantineTheme,
} from "@mantine/core";
import {useIsomorphicEffect} from "@mantine/hooks";
import {getContrastColor} from "../../../utilites/themeUtils.ts";
import {createAccentPalette, getPublicPalette, PublicThemeMode, readableAccent} from "./palette.ts";
import {useConsentBannerTheme} from "../../../utilites/consentBannerTheme.ts";

export const PUBLIC_OVERLAY_ROOT_ATTRIBUTE = 'data-public-overlay-root';

interface PublicOverlayThemeProps {
    accentColor: string;
    mode: PublicThemeMode;
    fontFamily?: string;
}

const buildOverlayVariables = (
    parentTheme: MantineTheme,
    accentColor: string,
    mode: PublicThemeMode,
    fontFamily?: string,
): Record<string, string> => {
    const palette = getPublicPalette(mode);
    const theme = mergeMantineTheme(parentTheme, {
        colors: {primary: createAccentPalette(accentColor)},
        primaryColor: 'primary',
        primaryShade: mode === 'dark' ? 6 : 7,
    });
    const defaults = defaultCssVariablesResolver(theme);
    const accentText = readableAccent(accentColor, palette.surface);

    const colorVariables = Object.keys(theme.colors).reduce<Record<string, string>>((variables, color) => ({
        ...variables,
        ...getCSSColorVariables({theme, color, colorScheme: mode, withColorValues: color === 'primary'}),
    }), {});

    const primaryAliases = Object.fromEntries(
        Object.entries(defaults.variables).filter(([name]) => name.startsWith('--mantine-primary-color'))
    );

    return {
        ...primaryAliases,
        ...defaults[mode],
        ...colorVariables,
        '--mantine-color-body': palette.surface,
        '--mantine-color-text': palette.textPrimary,
        '--mantine-color-dimmed': palette.textSecondary,
        '--mantine-color-default-border': palette.border,
        '--mantine-color-anchor': accentText,
        '--mantine-color-primary-text': accentText,
        '--mantine-color-primary-light-color': accentText,
        '--mantine-color-primary-outline': accentText,
        '--mantine-primary-color-light-color': accentText,
        '--mantine-primary-color-contrast': getContrastColor(accentColor),
        '--hi-text': palette.textPrimary,
        ...(fontFamily ? {
            '--mantine-font-family': fontFamily,
            '--mantine-font-family-headings': fontFamily,
            'font-family': fontFamily,
        } : {}),
        'color': palette.textPrimary,
    };
};

/**
 * Renders the modals, drawers, popovers and dropdowns opened inside public event and organizer pages
 * in a dedicated container that follows the page palette: dark surface and light text on dark pages,
 * white surface and dark text on light pages, accent colors adjusted to stay readable.
 */
export const PublicOverlayTheme = ({accentColor, mode, fontFamily, children}: PropsWithChildren<PublicOverlayThemeProps>) => {
    const parentTheme = useMantineTheme();
    useConsentBannerTheme(accentColor, mode, fontFamily);
    const [root] = useState<HTMLDivElement | null>(() => (
        typeof document === 'undefined' ? null : document.createElement('div')
    ));

    const variables = useMemo(
        () => buildOverlayVariables(parentTheme, accentColor, mode, fontFamily),
        [parentTheme, accentColor, mode, fontFamily]
    );

    useIsomorphicEffect(() => {
        if (!root) {
            return;
        }
        root.setAttribute(PUBLIC_OVERLAY_ROOT_ATTRIBUTE, '');
        root.setAttribute('data-mantine-color-scheme', mode);
        root.removeAttribute('style');
        Object.entries(variables).forEach(([name, value]) => root.style.setProperty(name, value));
        document.body.appendChild(root);

        return () => {
            root.remove();
        };
    }, [root, mode, variables]);

    const theme = useMemo<MantineThemeOverride>(() => {
        const accentContrast = getContrastColor(accentColor);

        return {
            components: {
                Portal: {
                    defaultProps: root ? {target: root} : {},
                },
                Button: {
                    vars: (_theme: MantineTheme, props: ButtonProps) => {
                        const usesPrimary = !props.color || props.color === 'primary';
                        if (usesPrimary && (props.variant === 'filled' || props.variant === undefined)) {
                            return {root: {'--button-color': accentContrast}};
                        }
                        return {root: {}};
                    },
                },
            },
        };
    }, [root, accentColor]);

    return (
        <MantineThemeProvider theme={theme}>
            {children}
        </MantineThemeProvider>
    );
};

export default PublicOverlayTheme;
