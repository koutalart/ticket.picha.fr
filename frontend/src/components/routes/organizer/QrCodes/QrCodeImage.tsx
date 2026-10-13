import {forwardRef} from "react";
import QRCode from "react-qr-code";
import {QrLinkDesign} from "../../../../types.ts";

export const DEFAULT_FOREGROUND = '#1c1530';
export const DEFAULT_BACKGROUND = '#ffffff';

interface QrCodeImageProps {
    value: string;
    design?: QrLinkDesign;
    size?: number;
}

export const QrCodeImage = forwardRef<HTMLDivElement, QrCodeImageProps>(({value, design, size = 160}, ref) => (
    <div ref={ref} style={{background: design?.background || DEFAULT_BACKGROUND, padding: 12, borderRadius: 8, lineHeight: 0}}>
        <QRCode
            value={value}
            size={size}
            level="M"
            fgColor={design?.foreground || DEFAULT_FOREGROUND}
            bgColor={design?.background || DEFAULT_BACKGROUND}
        />
    </div>
));

const fileName = (name: string, extension: string) => {
    const slug = name.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
        .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    return `qr-${slug || 'code'}.${extension}`;
};

const triggerDownload = (href: string, name: string) => {
    const link = document.createElement('a');
    link.href = href;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
};

const serializeSvg = (container: HTMLElement): string | null => {
    const svg = container.querySelector('svg');
    if (!svg) return null;

    const clone = svg.cloneNode(true) as SVGSVGElement;
    clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    return new XMLSerializer().serializeToString(clone);
};

export const downloadQrSvg = (container: HTMLElement | null, name: string) => {
    if (!container) return;
    const svg = serializeSvg(container);
    if (!svg) return;

    const url = URL.createObjectURL(new Blob([svg], {type: 'image/svg+xml'}));
    triggerDownload(url, fileName(name, 'svg'));
    setTimeout(() => URL.revokeObjectURL(url), 1000);
};

export const downloadQrPng = (container: HTMLElement | null, name: string, design?: QrLinkDesign, size = 1200) => {
    if (!container) return;
    const svg = serializeSvg(container);
    if (!svg) return;

    const margin = Math.round(size * 0.08);
    const canvas = document.createElement('canvas');
    canvas.width = size + margin * 2;
    canvas.height = size + margin * 2;
    const context = canvas.getContext('2d');
    if (!context) return;

    const image = new Image();
    image.onload = () => {
        context.fillStyle = design?.background || DEFAULT_BACKGROUND;
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.imageSmoothingEnabled = false;
        context.drawImage(image, margin, margin, size, size);
        triggerDownload(canvas.toDataURL('image/png'), fileName(name, 'png'));
    };
    image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
};
