import {removeTransparency} from "./colorHelper.ts";

interface SsrLoaderTheme {
    background: string;
    accent: string;
    mode?: string;
    backgroundType?: string;
    logoUrl?: string | null;
}

/**
 * Colour of the page while it loads. A mirrored cover image is not a colour,
 * so its dark or light mode is used instead of the (unrelated) background value.
 */
export const ssrLoaderBackground = ({background, mode, backgroundType}: SsrLoaderTheme): string => {
    if (backgroundType === 'MIRROR_COVER_IMAGE') {
        return mode === 'dark' ? '#0b0b0f' : '#ffffff';
    }

    return removeTransparency(background);
};

/**
 * Loading screen styled with the page theme: same background as the page, then, only if
 * loading takes more than 300 ms, the organizer logo breathing in the centre (or a small
 * ring in the accent colour when there is no logo).
 */
export const ssrLoaderCss = (theme: SsrLoaderTheme): string => {
    const background = ssrLoaderBackground(theme);
    const accent = removeTransparency(theme.accent);
    const mark = theme.logoUrl
        ? `background: url(${JSON.stringify(theme.logoUrl)}) center / contain no-repeat; border-radius: 16px;`
        : `border-radius: 50%; border: 3px solid transparent; border-top-color: ${accent}; border-right-color: ${accent};`;
    const motion = theme.logoUrl
        ? 'ssrLoaderBreathe 1.2s ease-in-out 0.6s infinite'
        : 'ssrLoaderSpin 0.9s linear 0.3s infinite';

    return `
        body, .ssr-loader { background-color: ${background} !important; }
        .ssr-loader::after {
            content: "";
            position: absolute;
            inset: 0;
            margin: auto;
            width: 72px;
            height: 72px;
            opacity: 0;
            ${mark}
            animation: ssrLoaderIn 0.3s ease 0.3s forwards, ${motion};
        }
        @keyframes ssrLoaderIn { to { opacity: 1; } }
        @keyframes ssrLoaderBreathe { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.7; transform: scale(0.92); } }
        @keyframes ssrLoaderSpin { to { opacity: 1; transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) {
            .ssr-loader::after { animation: ssrLoaderIn 0.3s ease 0.3s forwards; }
        }
    `;
};
