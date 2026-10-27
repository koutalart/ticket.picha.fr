// Links the server-rendered page to the JS and CSS of its route, read from the Vite client manifest,
// so the browser fetches them in parallel with the main bundle instead of discovering them one level at a time.
const PUBLIC_EVENT_ROUTES = [
    {pattern: /^\/event\/\d+\/(?!preview(?:\/|$))[^/]+\/?$/, module: 'src/components/layouts/PublicEvent/index.tsx'},
    {pattern: /^\/event\/\d+\/[^/]+\/[^/]+\/?$/, module: 'src/components/layouts/EventDedicatedPage/index.tsx'},
    {pattern: /^\/e\/\d+\/[^/]+\/?$/, module: 'src/components/layouts/EventHomepage/index.tsx'},
];

const BUY_INTENT = /(?:^|&)acheter=(?!0(?:&|$)|false(?:&|$))/;

const attribute = (value) => String(value).replace(/[&"<>]/g, (char) => (
    {'&': '&amp;', '"': '&quot;', '<': '&lt;', '>': '&gt;'}[char]
));

export const createRouteAssets = (manifest, base = '/') => {
    const entryKey = Object.keys(manifest).find((key) => manifest[key]?.isEntry);
    const loadedByEntry = new Set();

    const collect = (key, files, styles, seen = new Set()) => {
        const chunk = manifest[key];
        if (!chunk || seen.has(key)) {
            return;
        }
        seen.add(key);
        (chunk.imports || []).forEach((imported) => collect(imported, files, styles, seen));
        files.add(chunk.file);
        (chunk.css || []).forEach((css) => styles.add(css));
    };

    if (entryKey) {
        const styles = new Set();
        collect(entryKey, loadedByEntry, styles);
        styles.forEach((css) => loadedByEntry.add(css));
    }

    /**
     * @param {string} originalUrl
     * @param {{locale?: string, customerAddressForm?: string}} options
     * @returns {{html: string, showsContentBeforeHydration: boolean}}
     */
    return (originalUrl, {locale, customerAddressForm} = {}) => {
        const [pathname, search = ''] = originalUrl.split('?');
        const route = PUBLIC_EVENT_ROUTES.find(({pattern}) => pattern.test(pathname));
        if (!route || !manifest[route.module]) {
            return {html: '', showsContentBeforeHydration: false};
        }

        const files = new Set();
        const styles = new Set();
        collect(route.module, files, styles);

        const catalog = locale === 'fr' && customerAddressForm === 'tu' ? 'fr-tu' : locale;
        if (catalog) {
            collect(`src/locales/${catalog}.po`, files, styles);
        }

        const href = (file) => attribute(`${base}${file}`);
        const html = [
            ...[...styles].filter((css) => !loadedByEntry.has(css)).map((css) => `<link rel="stylesheet" crossorigin href="${href(css)}">`),
            ...[...files].filter((file) => !loadedByEntry.has(file)).map((file) => `<link rel="modulepreload" crossorigin href="${href(file)}">`),
        ].join('');

        return {
            html,
            // The visitor who came to buy waits under the loader until the page has scrolled to the ticket form.
            showsContentBeforeHydration: !BUY_INTENT.test(search),
        };
    };
};
