import express from "express";
import {installGlobals} from "@remix-run/node";
import process from "process";
import {createServer as viteServer} from "vite";
import compression from "compression";
import fs from "node:fs/promises";
import sirv from "sirv";
import cookieParser from "cookie-parser";
import path from "node:path";
import {fileURLToPath} from "node:url";
import * as nodePath from "node:path";
import * as nodeUrl from "node:url";
import "dotenv/config";
import {sitemapIndexHandler, sitemapEventsHandler, sitemapOrganizersHandler} from "./src/sitemap/proxy.js";
import {createRouteAssets} from "./src/ssr/routeAssets.js";

installGlobals();

async function main() {
    const base = process.env.BASE || "/";
    const port = process.argv.includes("--port")
        ? process.argv[process.argv.indexOf("--port") + 1]
        : process.env.NODE_PORT || 5678;
    const isProduction = process.env.NODE_ENV === "production";

    const __dirname = path.dirname(fileURLToPath(import.meta.url));

    const templateHtml = isProduction
        ? await fs.readFile("./dist/client/index.html", "utf-8")
        : "";

    const ssrManifest = isProduction
        ? await fs.readFile("./dist/client/.vite/ssr-manifest.json", "utf-8")
        : undefined;

    const app = express();
    app.disable('x-powered-by');
    app.use(cookieParser());

    const EMBEDDABLE_PATHS = /^\/widget(\/|$|\?)/;
    app.use((req, res, next) => {
        const embeddable = EMBEDDABLE_PATHS.test(req.path);
        res.setHeader(
            'Content-Security-Policy',
            `base-uri 'self'; object-src 'none'; frame-ancestors ${embeddable ? '*' : "'self'"}`
        );
        if (!embeddable) {
            res.setHeader('X-Frame-Options', 'SAMEORIGIN');
        }
        res.setHeader('X-Content-Type-Options', 'nosniff');
        res.setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        next();
    });

    app.use('/.well-known', express.static(path.join(__dirname, 'public/.well-known')));

    let vite;

    if (!isProduction) {
        vite = await viteServer({
            server: { middlewareMode: true },
            appType: "custom",
            base,
        });

        app.use(vite.middlewares);
    } else {
        app.use(compression());
        app.use(base, sirv(path.join(__dirname, "./dist/client"), {
            extensions: [],
            brotli: true,
            gzip: true,
            setHeaders: (res, pathname) => {
                if (pathname.startsWith('/assets/')) {
                    res.setHeader('Cache-Control', 'public, max-age=31536000, immutable');
                }
            },
        }));
    }

    const routeAssets = createRouteAssets(isProduction
        ? JSON.parse(await fs.readFile("./dist/client/.vite/manifest.json", "utf-8").catch(() => "{}"))
        : {});

    const CUSTOM_DOMAIN_HEADER = 'x-picha-custom-domain-organizer';
    const CUSTOM_DOMAIN_CACHE_TTL_MS = 60 * 1000;
    const PLATFORM_ONLY_PATHS = /^\/(manage|admin|auth|account|welcome|kiosk|profile)(\/|$|\?)/;
    const PARTICIPANT_PATHS = ['/event/', '/events/', '/e/', '/checkout/', '/product/', '/venue/', '/my-tickets/', '/q/'];
    const customDomainCache = new Map();

    const normalizeHost = (host) => (host || '').toLowerCase().replace(/:\d+$/, '').replace(/^www\./, '');
    const platformUrl = (process.env.VITE_FRONTEND_URL || '').replace(/\/$/, '');
    const platformHost = platformUrl ? normalizeHost(new URL(platformUrl).host) : null;

    const resolveCustomDomain = async (host) => {
        if (!host || host === platformHost || host === 'localhost' || /^[\d.]+$/.test(host) || !host.includes('.')) {
            return null;
        }

        const cached = customDomainCache.get(host);
        if (cached && cached.expiresAt > Date.now()) {
            return cached.organizer;
        }

        try {
            const response = await fetch(
                `${process.env.VITE_API_URL_SERVER}/public/custom-domains/${encodeURIComponent(host)}`,
                {headers: {Accept: 'application/json'}, signal: AbortSignal.timeout(5000)}
            );

            if (response.ok || response.status === 404) {
                const organizer = response.ok ? (await response.json()).data : null;
                customDomainCache.set(host, {organizer, expiresAt: Date.now() + CUSTOM_DOMAIN_CACHE_TTL_MS});
                return organizer;
            }
        } catch (error) {
            console.error(`Custom domain lookup failed for ${host}`, error);
        }

        return cached?.organizer ?? null;
    };

    // Participant pages opened on the platform for an organizer that has its own domain are moved onto that domain.
    // Checkout steps before the summary stay where they are: the checkout session lives in the tab's sessionStorage.
    const EVENT_PARTICIPANT_PATH = /^\/(?:event|e)\/(\d+)\/(?!preview(?:\/|$))[^/]+(?:\/[^/]+)?\/?$|^\/checkout\/(\d+)\/[^/]+\/summary\/?$|^\/(?:product|venue)\/(\d+)\/[^/]+(?:\/print)?\/?$|^\/order\/(\d+)\/[^/]+\/print\/?$/;
    const eventDomainCache = new Map();

    const resolveEventCustomDomain = async (eventId) => {
        const cached = eventDomainCache.get(eventId);
        if (cached && cached.expiresAt > Date.now()) {
            return cached.domain;
        }

        try {
            const response = await fetch(
                `${process.env.VITE_API_URL_SERVER}/public/events/${encodeURIComponent(eventId)}`,
                {headers: {Accept: 'application/json'}, signal: AbortSignal.timeout(5000)}
            );

            if (response.ok || response.status === 404) {
                const domain = response.ok
                    ? normalizeHost((await response.json()).data?.organizer?.custom_domain || '') || null
                    : null;
                eventDomainCache.set(eventId, {domain, expiresAt: Date.now() + CUSTOM_DOMAIN_CACHE_TTL_MS});
                return domain;
            }
        } catch (error) {
            console.error(`Event custom domain lookup failed for event ${eventId}`, error);
        }

        return cached?.domain ?? null;
    };

    const isPublicPlatformHost = (host) => !!platformHost
        && host === platformHost
        && platformHost !== 'localhost'
        && platformHost.includes('.')
        && !/^[\d.]+$/.test(platformHost);

    const participantCustomDomainRedirect = async (req, requestHost) => {
        if (req.method !== 'GET' || !isPublicPlatformHost(requestHost)) {
            return null;
        }

        const match = req.originalUrl.split('?')[0].match(EVENT_PARTICIPANT_PATH);
        const eventId = match?.slice(1).find(Boolean);
        if (!eventId) {
            return null;
        }

        const domain = await resolveEventCustomDomain(eventId);

        return domain && domain !== platformHost ? `https://${domain}${req.originalUrl}` : null;
    };

    const SERVER_ONLY_ENV_KEYS = new Set(['VITE_API_URL_SERVER']);

    const getViteEnvironmentVariables = (overrides = {}) => {
        const envVars = {};
        for (const key in process.env) {
            if (key.startsWith('VITE_') && !SERVER_ONLY_ENV_KEYS.has(key)) {
                envVars[key] = process.env[key];
            }
        }
        return JSON.stringify({...envVars, ...overrides});
    };

    const storageBaseUrl = (apiUrl) => apiUrl ? `${apiUrl.replace(/\/$/, '')}/storage/` : null;

    const xmlEscape = (value) => String(value).replace(/[<>&'"]/g, (char) => (
        {'<': '&lt;', '>': '&gt;', '&': '&amp;', "'": '&apos;', '"': '&quot;'}[char]
    ));

    const customDomainSitemap = async (organizer, res) => {
        const apiUrl = process.env.VITE_API_URL_SERVER;
        const origin = `https://${organizer.domain}`;
        const [organizerResponse, eventsResponse] = await Promise.all([
            fetch(`${apiUrl}/public/organizers/${organizer.id}`, {headers: {Accept: 'application/json'}}),
            fetch(`${apiUrl}/public/organizers/${organizer.id}/events?per_page=100&eventsStatus=upcoming`, {headers: {Accept: 'application/json'}}),
        ]);
        const organizerData = organizerResponse.ok ? (await organizerResponse.json()).data : null;
        const events = eventsResponse.ok ? (await eventsResponse.json()).data : [];
        const hasServices = (organizerData?.site_content?.services || []).length > 0;

        const pages = ['', 'evenements', 'a-propos', ...(hasServices ? ['services'] : []), 'partenaires', 'contact'];
        const urls = [
            ...pages.map((page) => ({loc: `${origin}/${page}`, priority: page === '' ? '1.0' : '0.7', changefreq: 'weekly'})),
            ...events.flatMap((event) => [
                {loc: `${origin}/event/${event.id}/${event.slug}`, priority: '0.9', changefreq: 'daily'},
                ...(event.dedicated_pages || []).map((page) => ({
                    loc: `${origin}/event/${event.id}/${event.slug}/${page}`,
                    priority: '0.7',
                    changefreq: 'weekly',
                })),
            ]),
        ];

        const body = urls.map(({loc, priority, changefreq}) =>
            `  <url><loc>${xmlEscape(loc)}</loc><changefreq>${changefreq}</changefreq><priority>${priority}</priority></url>`
        ).join('\n');

        res.setHeader('Content-Type', 'application/xml');
        res.setHeader('Cache-Control', 'public, max-age=3600');
        res.status(200).send(`<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${body}\n</urlset>`);
    };

    app.get('/robots.txt', async (req, res) => {
        const customDomain = await resolveCustomDomain(normalizeHost(req.get('host')));
        const frontendUrl = customDomain
            ? `https://${customDomain.domain}`
            : process.env.VITE_FRONTEND_URL || `${req.protocol}://${req.get('host')}`;
        const disallow = customDomain ? '\nDisallow: /checkout/\nDisallow: /order/' : '';
        const robotsTxt = `User-agent: *
Allow: /${disallow}

Sitemap: ${frontendUrl}/sitemap.xml
`;
        res.setHeader('Content-Type', 'text/plain');
        res.setHeader('Cache-Control', 'public, max-age=86400');
        res.status(200).send(robotsTxt);
    });

    const loadServerEntry = async () => isProduction
        ? dynamicImport(path.join(__dirname, "./dist/server/entry.server.js"))
        : vite.ssrLoadModule("/src/entry.server.tsx");
    const platformBaseUrl = (req) => (process.env.VITE_FRONTEND_URL || `${req.protocol}://${req.get('host')}`).replace(/\/$/, '');

    app.get('/sitemap-pages.xml', async (req, res, next) => {
        if (await resolveCustomDomain(normalizeHost(req.get('host')))) {
            return next();
        }
        const {renderMarketingSitemap} = await loadServerEntry();
        res.setHeader('Content-Type', 'application/xml');
        res.setHeader('Cache-Control', 'public, max-age=3600');
        res.status(200).send(renderMarketingSitemap(platformBaseUrl(req)));
    });

    const fetchOrganizerForLlms = async (organizerId) => {
        const apiUrl = process.env.VITE_API_URL_SERVER;
        const options = {headers: {Accept: 'application/json'}, signal: AbortSignal.timeout(5000)};
        const [organizerResponse, eventsResponse] = await Promise.all([
            fetch(`${apiUrl}/public/organizers/${encodeURIComponent(organizerId)}`, options),
            fetch(`${apiUrl}/public/organizers/${encodeURIComponent(organizerId)}/events?per_page=50&eventsStatus=upcoming`, options),
        ]);
        if (!organizerResponse.ok) {
            return null;
        }
        return {
            organizer: (await organizerResponse.json()).data,
            events: eventsResponse.ok ? (await eventsResponse.json()).data || [] : [],
        };
    };

    const sendOrganizerLlmsTxt = async (res, organizerId, origin, customDomain) => {
        const data = await fetchOrganizerForLlms(organizerId);
        if (!data?.organizer) {
            return res.status(404).type('text/plain').send('Not found');
        }
        const organizerDomain = data.organizer.custom_domain;
        const {renderOrganizerLlmsTxt} = await loadServerEntry();
        res.setHeader('Content-Type', 'text/plain; charset=utf-8');
        res.setHeader('Cache-Control', 'public, max-age=3600');
        res.status(200).send(renderOrganizerLlmsTxt({
            origin: organizerDomain ? `https://${organizerDomain}` : origin,
            organizer: data.organizer,
            events: data.events,
            customDomain: customDomain || !!organizerDomain,
        }));
    };

    app.get('/llms.txt', async (req, res) => {
        const customDomain = await resolveCustomDomain(normalizeHost(req.get('host')));
        if (customDomain) {
            try {
                return await sendOrganizerLlmsTxt(res, customDomain.id, `https://${customDomain.domain}`, true);
            } catch (error) {
                console.error(`Custom domain llms.txt failed for ${customDomain.domain}`, error);
                return res.status(500).send('Internal server error');
            }
        }
        const {renderLlmsTxt} = await loadServerEntry();
        res.setHeader('Content-Type', 'text/plain; charset=utf-8');
        res.setHeader('Cache-Control', 'public, max-age=3600');
        res.status(200).send(renderLlmsTxt(platformBaseUrl(req)));
    });

    app.get(/^\/events\/(\d+)\/[^/]+\/llms\.txt$/, async (req, res, next) => {
        if (await resolveCustomDomain(normalizeHost(req.get('host')))) {
            return next();
        }
        try {
            await sendOrganizerLlmsTxt(res, req.params[0], platformBaseUrl(req), false);
        } catch (error) {
            console.error('Organizer llms.txt failed', error);
            res.status(500).send('Internal server error');
        }
    });

    app.get('/sitemap.xml', async (req, res) => {
        const customDomain = await resolveCustomDomain(normalizeHost(req.get('host')));
        if (!customDomain) {
            return sitemapIndexHandler(req, res, [`${platformBaseUrl(req)}/sitemap-pages.xml`]);
        }
        try {
            await customDomainSitemap(customDomain, res);
        } catch (error) {
            console.error(`Custom domain sitemap failed for ${customDomain.domain}`, error);
            res.status(500).send('Internal server error');
        }
    });
    const QR_SHORT_ID = /^[A-Za-z0-9]{4,32}$/;
    // "/q/abc?acheter=1" keeps the buy intent on the event page (the "#tickets" fragment is kept by the browser).
    const withBuyIntent = (redirectUrl, req) => {
        const intent = req.query?.acheter;
        if (intent === undefined || Array.isArray(intent)) {
            return redirectUrl;
        }
        try {
            const target = new URL(redirectUrl, platformBaseUrl(req));
            if (!target.searchParams.has('acheter')) {
                target.searchParams.set('acheter', String(intent) || '1');
            }
            return target.toString();
        } catch {
            return redirectUrl;
        }
    };
    const clientIp = (req) => (req.headers['x-forwarded-for'] || '').split(',').pop().trim() || req.socket?.remoteAddress || '';

    app.get('/q/:shortId', async (req, res, next) => {
        const {shortId} = req.params;
        if (!QR_SHORT_ID.test(shortId)) {
            return next();
        }

        try {
            const headers = {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'User-Agent': req.get('user-agent') || '',
                'X-Forwarded-For': clientIp(req),
            };
            if (req.get('cf-ipcountry')) {
                headers['CF-IPCountry'] = req.get('cf-ipcountry');
            }

            const response = await fetch(
                `${process.env.VITE_API_URL_SERVER}/public/qr-links/${encodeURIComponent(shortId.toLowerCase())}/visits`,
                {
                    method: 'POST',
                    headers,
                    body: JSON.stringify({type: 'SCAN', referrer: req.get('referer') || null}),
                    signal: AbortSignal.timeout(3000),
                }
            );

            if (response.ok) {
                const {data} = await response.json();
                if (data?.redirect_url) {
                    res.setHeader('Cache-Control', 'no-store');
                    return res.redirect(302, withBuyIntent(data.redirect_url, req));
                }
            }
        } catch (error) {
            console.error(`QR scan recording failed for ${shortId}`, error);
        }

        res.setHeader('Cache-Control', 'no-store');
        return next();
    });

    // Private organizer calendar: the page shell imported with calendar:import, relayed as is.
    // Served here so its relative /api calls stay on the same origin, custom domains included.
    const CALENDAR_SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
    app.get('/calendrier/:slug', async (req, res, next) => {
        const {slug} = req.params;
        if (!CALENDAR_SLUG.test(slug) || slug.length > 100) {
            return next();
        }

        try {
            const response = await fetch(
                `${process.env.VITE_API_URL_SERVER}/public/calendriers/${encodeURIComponent(slug)}/page`,
                {headers: {Accept: 'text/html'}, signal: AbortSignal.timeout(5000)}
            );

            if (response.ok) {
                res.setHeader('Cache-Control', 'no-store');
                res.setHeader('X-Robots-Tag', 'noindex, nofollow');
                res.setHeader('Referrer-Policy', 'no-referrer');
                res.setHeader('Content-Type', 'text/html; charset=utf-8');
                return res.status(200).send(await response.text());
            }
        } catch (error) {
            console.error(`Calendar page failed for ${slug}`, error);
        }

        res.setHeader('Cache-Control', 'no-store');
        return next();
    });

    app.get('/sitemap-events-:page.xml', sitemapEventsHandler);
    app.get('/sitemap-organizers-:page.xml', sitemapOrganizersHandler);

    app.use("*", async (req, res) => {
        let url = req.originalUrl.replace(base, "");

        delete req.headers[CUSTOM_DOMAIN_HEADER];

        if (process.env.VITE_DEFAULT_LOCALE && !req.cookies?.locale && !req.headers['accept-language']) {
            req.headers['accept-language'] = process.env.VITE_DEFAULT_LOCALE;
        }

        const requestHost = normalizeHost(req.get('host'));
        const customDomain = await resolveCustomDomain(requestHost);

        if (customDomain) {
            if (PLATFORM_ONLY_PATHS.test(req.originalUrl) && platformUrl) {
                res.redirect(302, `${platformUrl}${req.originalUrl}`);
                return;
            }

            const [pathname, search] = req.originalUrl.split('?');
            if (new RegExp(`^/events/${customDomain.id}/[^/]+/?$`).test(pathname)) {
                const homeSearch = customDomain.current_event ? ['accueil', search].filter(Boolean).join('&') : search;
                res.redirect(302, `/${homeSearch ? `?${homeSearch}` : ''}`);
                return;
            }

            const sitePageMatch = pathname.match(new RegExp(`^/events/${customDomain.id}/[^/]+/(a-propos|evenements|services|partenaires|contact)/?$`));
            if (sitePageMatch) {
                res.redirect(302, `/${sitePageMatch[1]}${search ? `?${search}` : ''}`);
                return;
            }

            if (pathname === '/' && customDomain.current_event && !new URLSearchParams(search || '').has('accueil')) {
                res.setHeader('Cache-Control', 'no-store');
                res.redirect(302, `/event/${customDomain.current_event.id}/${customDomain.current_event.slug}${search ? `?${search}` : ''}`);
                return;
            }

            if (!req.cookies?.locale && !req.headers['accept-language']) {
                req.headers['accept-language'] = 'fr';
            }

            req.headers[CUSTOM_DOMAIN_HEADER] = JSON.stringify(customDomain);
        } else {
            const participantRedirect = await participantCustomDomainRedirect(req, requestHost);
            if (participantRedirect) {
                res.setHeader('Cache-Control', 'no-store');
                res.redirect(302, participantRedirect);
                return;
            }
        }

        try {
            let template;
            let render;

            if (!isProduction) {
                template = await fs.readFile(path.join(__dirname, "./index.html"), "utf-8");
                template = await vite.transformIndexHtml(url, template);
                render = (await vite.ssrLoadModule("/src/entry.server.tsx")).render;
            } else {
                template = templateHtml;
                render = (await dynamicImport(path.join(__dirname, "./dist/server/entry.server.js"))).render;
            }

            const { appHtml, dehydratedState, helmetContext, locale, htmlLang, statusCode, customerAddressForm } = await render(
                { req, res },
                ssrManifest
            );
            const stringifiedState = JSON.stringify(dehydratedState);

            let helmetHtml = Object.values(helmetContext.helmet || {})
                .map((value) => value.toString() || "")
                .join(" ");

            let pageHtml = appHtml;
            const headSnippets = [];

            if (customDomain && platformUrl) {
                for (const path of PARTICIPANT_PATHS) {
                    helmetHtml = helmetHtml.split(`${platformUrl}${path}`).join(`https://${requestHost}${path}`);
                }
            }

            // Images and videos of an organizer with its own domain are served from that domain (proxied /api/):
            // the browser reuses the page connection and link previews see og:image on the og:url domain.
            const platformStorageUrl = storageBaseUrl(process.env.VITE_API_URL_CLIENT);
            let stateJson = stringifiedState;
            if (customDomain && platformStorageUrl) {
                const protocol = (req.get('x-forwarded-proto') || req.protocol).split(',')[0].trim() === 'http' ? 'http' : 'https';
                const host = /^[a-z0-9.-]+(:\d+)?$/i.test(req.get('host') || '') ? req.get('host') : requestHost;
                const customStorageUrl = `${protocol}://${host}/api/storage/`;
                helmetHtml = helmetHtml.split(platformStorageUrl).join(customStorageUrl);
                pageHtml = pageHtml.split(platformStorageUrl).join(customStorageUrl);
                stateJson = stateJson.split(platformStorageUrl).join(customStorageUrl);
            }

            const assets = routeAssets(req.originalUrl, {locale, customerAddressForm});
            if (assets.html && assets.showsContentBeforeHydration) {
                headSnippets.push('<style>.ssr-loader{display:none!important}</style>');
            }

            const envVariablesHtml = `<script>window.hievents = ${getViteEnvironmentVariables(
                customDomain ? {VITE_FRONTEND_URL: `https://${requestHost}`} : {}
            )};window.__CUSTOM_DOMAIN_ORGANIZER__ = ${JSON.stringify(customDomain).replace(/</g, '\\u003c')};</script>`;

            if (process.env.VITE_FATHOM_SITE_ID) {
                headSnippets.push(`
                <script src="https://cdn.usefathom.com/script.js" data-spa="auto" data-site="${process.env.VITE_FATHOM_SITE_ID}" defer></script>
            `);
            }

            const html = template
                .replace('<html lang="en">', `<html lang="${htmlLang}" data-locale="${locale}"${customerAddressForm === 'tu' ? ' data-address-form="tu"' : ''}>`)
                .replace("<!--head-snippets-->", headSnippets.join("\n"))
                .replace("<!--app-html-->", () => pageHtml)
                .replace("<!--dehydrated-state-->", () => `<script>window.__REHYDRATED_STATE__ = ${stateJson}</script>`)
                .replace("<!--environment-variables-->", () => envVariablesHtml)
                .replace(/<!--render-helmet-->.*?<!--\/render-helmet-->/s, () => helmetHtml)
                .replace("</head>", () => `${assets.html}</head>`);

            res.setHeader("Content-Type", "text/html; charset=utf-8");
            return res.status(statusCode || 200).end(html);
        } catch (error) {
            if (error instanceof Response) {
                if (error.status >= 300 && error.status < 400) {
                    return res.redirect(error.status, error.headers.get("Location") || "/");
                } else {
                    return res.status(error.status).send(await error.text());
                }
            }

            console.error(error);
            res.status(500).send("Internal Server Error");
        }
    });

    app.listen(port, () => {
        console.info(`SSR Serving at http://localhost:${port}`);
    });

    const dynamicImport = async (path) => {
        return import(
            nodePath.isAbsolute(path) ? nodeUrl.pathToFileURL(path).toString() : path
        );
        
    }
}
main();