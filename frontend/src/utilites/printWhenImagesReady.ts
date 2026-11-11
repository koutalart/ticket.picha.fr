const MAX_WAIT_MS = 8000;

export const printWhenImagesReady = (): (() => void) => {
    let cancelled = false;
    const images = Array.from(document.images);
    images.forEach(image => {
        image.loading = 'eager';
    });
    const loaded = images.map(image => image.complete && image.naturalWidth > 0
        ? Promise.resolve()
        : image.decode().catch(() => undefined));
    const timeout = new Promise<void>(resolve => window.setTimeout(resolve, MAX_WAIT_MS));

    Promise.race([Promise.all(loaded), timeout]).then(() => {
        if (!cancelled) {
            window.setTimeout(() => window.print(), 200);
        }
    });

    return () => {
        cancelled = true;
    };
};
