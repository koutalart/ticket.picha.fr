import {useEffect, useState} from "react";

export type LogoPlate = 'light' | 'dark' | undefined;

const cache = new Map<string, LogoPlate>();

const detectPlate = (image: HTMLImageElement): LogoPlate => {
    const size = 48;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const context = canvas.getContext('2d');
    if (!context) {
        return undefined;
    }
    context.drawImage(image, 0, 0, size, size);
    const {data} = context.getImageData(0, 0, size, size);
    let opaque = 0;
    let light = 0;
    let dark = 0;
    for (let i = 0; i < data.length; i += 4) {
        if (data[i + 3] < 200) {
            continue;
        }
        opaque++;
        const luminance = 0.2126 * data[i] + 0.7152 * data[i + 1] + 0.0722 * data[i + 2];
        if (luminance > 215) {
            light++;
        } else if (luminance < 170) {
            dark++;
        }
    }
    const alphaAt = (x: number, y: number) => data[(y * size + x) * 4 + 3];
    const cornersOpaque = [alphaAt(0, 0), alphaAt(size - 1, 0), alphaAt(0, size - 1), alphaAt(size - 1, size - 1)]
        .every((alpha) => alpha > 250);
    if (opaque > 0 && dark > 0 && light / opaque >= 0.4) {
        return 'light';
    }
    return cornersOpaque ? 'dark' : undefined;
};

export const useLogoPlate = (url?: string): LogoPlate => {
    const [plate, setPlate] = useState<LogoPlate>(() => (url ? cache.get(url) : undefined));

    useEffect(() => {
        if (!url || typeof window === 'undefined') {
            setPlate(undefined);
            return;
        }
        if (cache.has(url)) {
            setPlate(cache.get(url)!);
            return;
        }
        let active = true;
        const image = new Image();
        image.crossOrigin = 'anonymous';
        image.onload = () => {
            let result: LogoPlate;
            try {
                result = detectPlate(image);
            } catch {
                result = undefined;
            }
            cache.set(url, result);
            if (active) {
                setPlate(result);
            }
        };
        image.src = url;
        return () => {
            active = false;
        };
    }, [url]);

    return plate;
};
