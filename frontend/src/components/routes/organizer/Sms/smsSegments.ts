const GSM_7BIT = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
const GSM_7BIT_EXTENDED = "^{}\\[~]|€";

export interface SmsSegmentInfo {
    characters: number;
    segments: number;
    unicode: boolean;
}

export const countSmsSegments = (text: string): SmsSegmentInfo => {
    const chars = Array.from(text);
    const unicode = chars.some(char => !GSM_7BIT.includes(char) && !GSM_7BIT_EXTENDED.includes(char));
    const units = unicode
        ? chars.length
        : chars.reduce((total, char) => total + (GSM_7BIT_EXTENDED.includes(char) ? 2 : 1), 0);

    const single = unicode ? 70 : 160;
    const multi = unicode ? 67 : 153;
    const segments = units === 0 ? 0 : units <= single ? 1 : Math.ceil(units / multi);

    return {characters: chars.length, segments, unicode};
};
