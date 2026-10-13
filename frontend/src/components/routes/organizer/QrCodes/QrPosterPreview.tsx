import type {CSSProperties} from "react";
import QRCode from "react-qr-code";
import {QrLinkDesign, QrPosterTemplate} from "../../../../types.ts";
import {DEFAULT_BACKGROUND, DEFAULT_FOREGROUND} from "./QrCodeImage.tsx";
import classes from "./QrCodes.module.scss";

// Mirrors QrPosterRenderer::PRESETS on the backend, which produces the downloadable file.
export const POSTER_PRESETS: Record<Exclude<QrPosterTemplate, 'none' | 'custom'>, {
    width: number,
    height: number,
    qrCenter: number,
    qrScale: number,
}> = {
    story: {width: 1080, height: 1920, qrCenter: 0.56, qrScale: 0.6},
    poster: {width: 1240, height: 1754, qrCenter: 0.6, qrScale: 0.5},
    square: {width: 1080, height: 1080, qrCenter: 0.5, qrScale: 0.5},
};

const TITLE_LAYOUT: Record<string, { top: number, size: number, lines: number }> = {
    story: {top: 0.19, size: 7.5, lines: 3},
    poster: {top: 0.15, size: 7, lines: 2},
    square: {top: 0.12, size: 5, lines: 2},
};

const darken = (hex: string, factor: number) => {
    const value = hex.replace('#', '');
    const channels = [0, 2, 4].map(index => Math.round(parseInt(value.slice(index, index + 2), 16) * factor));
    return `rgb(${channels.join(',')})`;
};

interface QrPosterPreviewProps {
    value: string;
    design: QrLinkDesign;
    title?: string | null;
    width?: number | string;
}

export const QrPosterPreview = ({value, design, title, width = '100%'}: QrPosterPreviewProps) => {
    const template = design.template && design.template !== 'none' ? design.template : 'story';
    const foreground = design.foreground || DEFAULT_FOREGROUND;
    const isCustom = template === 'custom';
    const preset = isCustom ? null : POSTER_PRESETS[template];
    const canvasWidth = isCustom ? (design.background_width || 1080) : preset!.width;
    const canvasHeight = isCustom ? (design.background_height || 1920) : preset!.height;
    const ratio = canvasWidth / canvasHeight;
    const qrCenter = isCustom ? (design.qr_position ?? 50) / 100 : preset!.qrCenter;
    const qrScale = isCustom ? (design.qr_scale ?? 45) / 100 : preset!.qrScale;
    const cardTop = qrCenter * 100 - qrScale * ratio * 50;
    const captionTop = qrCenter * 100 + qrScale * ratio * 50 + 4.4 * ratio;
    const captionColor = design.caption_color || (template === 'poster' ? foreground : '#ffffff');

    const background: CSSProperties = isCustom
        ? {backgroundImage: design.background_image_url ? `url(${design.background_image_url})` : undefined, backgroundSize: 'cover', backgroundColor: '#ddd'}
        : template === 'story'
            ? {background: `linear-gradient(${foreground}, ${darken(foreground, 0.55)})`}
            : template === 'poster'
                ? {background: `linear-gradient(${foreground} 0 30%, #ffffff 30% 100%)`}
                : {background: foreground};

    const titleLayout = TITLE_LAYOUT[template];

    return (
        <div className={classes.poster} style={{width, aspectRatio: `${canvasWidth} / ${canvasHeight}`, ...background}}>
            {!isCustom && title && titleLayout && (
                <div
                    className={classes.posterTitle}
                    style={{
                        top: `${titleLayout.top * 100}%`,
                        fontSize: `${titleLayout.size}cqw`,
                        WebkitLineClamp: titleLayout.lines,
                    }}
                >
                    {title}
                </div>
            )}
            <div
                className={classes.posterCard}
                style={{
                    top: `${cardTop}%`,
                    width: `${qrScale * 100}%`,
                    background: design.background || DEFAULT_BACKGROUND,
                    border: template === 'poster' ? '1px solid #e4e1ec' : undefined,
                }}
            >
                <QRCode value={value} level="M" fgColor={foreground} bgColor={design.background || DEFAULT_BACKGROUND}
                        style={{width: '100%', height: 'auto', display: 'block'}}/>
            </div>
            {design.caption && (
                <div className={classes.posterCaption} style={{top: `${captionTop}%`, color: captionColor}}>
                    {design.caption}
                </div>
            )}
        </div>
    );
};
