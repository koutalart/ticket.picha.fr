import {useEffect, useState} from "react";

const cache = new Map<string, boolean>();

const cornersAreOpaque = (image: HTMLImageElement): boolean => {
    const size = 32;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const context = canvas.getContext('2d');
    if (!context) {
        return false;
    }
    context.drawImage(image, 0, 0, size, size);
    const {data} = context.getImageData(0, 0, size, size);
    const alphaAt = (x: number, y: number) => data[(y * size + x) * 4 + 3];
    return [alphaAt(0, 0), alphaAt(size - 1, 0), alphaAt(0, size - 1), alphaAt(size - 1, size - 1)]
        .every((alpha) => alpha > 250);
};

export const useOpaqueLogo = (url?: string): boolean => {
    const [opaque, setOpaque] = useState(() => (url ? cache.get(url) ?? false : false));

    useEffect(() => {
        if (!url || typeof window === 'undefined') {
            setOpaque(false);
            return;
        }
        if (cache.has(url)) {
            setOpaque(cache.get(url)!);
            return;
        }
        let active = true;
        const image = new Image();
        image.crossOrigin = 'anonymous';
        image.onload = () => {
            let result = false;
            try {
                result = cornersAreOpaque(image);
            } catch {
                result = false;
            }
            cache.set(url, result);
            if (active) {
                setOpaque(result);
            }
        };
        image.src = url;
        return () => {
            active = false;
        };
    }, [url]);

    return opaque;
};
