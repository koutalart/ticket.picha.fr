import {ReactNode, useMemo, useState} from "react";
import {ButtonProps, MantineTheme, MantineThemeOverride, MantineThemeProvider} from "@mantine/core";
import {useIsomorphicEffect} from "@mantine/hooks";

export const MESSAGING_ACCENT = '#008069';

const SCOPE_VARIABLES: Record<string, string> = {
    '--mantine-color-body': '#ffffff',
    '--mantine-color-text': '#111b21',
    '--mantine-color-dimmed': '#667781',
    '--mantine-color-default': '#ffffff',
    '--mantine-color-default-border': '#d1d7db',
    '--mantine-color-placeholder': '#8696a0',
    '--mantine-color-anchor': MESSAGING_ACCENT,
    '--mantine-primary-color-filled': MESSAGING_ACCENT,
    '--mantine-primary-color-filled-hover': '#006e5a',
    '--mantine-primary-color-light': 'rgba(0, 128, 105, 0.1)',
    '--mantine-primary-color-light-hover': 'rgba(0, 128, 105, 0.16)',
    '--mantine-primary-color-light-color': MESSAGING_ACCENT,
    '--mantine-primary-color-contrast': '#ffffff',
    '--mantine-color-primary-filled': MESSAGING_ACCENT,
    '--mantine-color-primary-filled-hover': '#006e5a',
    '--mantine-color-primary-light': 'rgba(0, 128, 105, 0.1)',
    '--mantine-color-primary-light-hover': 'rgba(0, 128, 105, 0.16)',
    '--mantine-color-primary-light-color': MESSAGING_ACCENT,
    '--mantine-color-primary-text': MESSAGING_ACCENT,
    'color': '#111b21',
    'color-scheme': 'light',
};

/**
 * The « Messagerie » chat always looks like a light messaging app, whatever the palette of the page: its overlays are
 * rendered in their own container with light colours and a green accent. The container is handed to the drawer as
 * its portal target: a DOM node cannot go through the theme, whose overrides are deep merged.
 */
export const LiveChatMessagingScope = ({children}: { children: (target: HTMLElement | undefined) => ReactNode }) => {
    const [root] = useState<HTMLDivElement | null>(() => (
        typeof document === 'undefined' ? null : document.createElement('div')
    ));

    useIsomorphicEffect(() => {
        if (!root) {
            return;
        }
        root.setAttribute('data-mantine-color-scheme', 'light');
        root.setAttribute('data-live-chat-messaging-root', '');
        Object.entries(SCOPE_VARIABLES).forEach(([name, value]) => root.style.setProperty(name, value));
        document.body.appendChild(root);

        return () => {
            root.remove();
        };
    }, [root]);

    const theme = useMemo<MantineThemeOverride>(() => ({
        components: {
            Button: {
                vars: (_theme: MantineTheme, props: ButtonProps) => {
                    const usesPrimary = !props.color || props.color === 'primary';
                    if (usesPrimary && (props.variant === 'filled' || props.variant === undefined)) {
                        return {root: {'--button-color': '#ffffff'}};
                    }
                    return {root: {}};
                },
            },
        },
    }), []);

    return (
        <MantineThemeProvider theme={theme}>
            {children(root ?? undefined)}
        </MantineThemeProvider>
    );
};
