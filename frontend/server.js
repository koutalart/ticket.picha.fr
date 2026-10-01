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
    app.use(cookieParser());

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
        app.use(base, sirv(path.join(__dirname, "./dist/client"), { extensions: [] }));
    }

    const CUSTOM_DOMAIN_HEADER = 'x-picha-custom-domain-organizer';
    const CUSTOM_DOMAIN_CACHE_TTL_MS = 60 * 1000;
    const PLATFORM_ONLY_PATHS = /^\/(manage|admin|auth|account|welcome|kiosk|profile)(\/|$|\?)/;
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

    const getViteEnvironmentVariables = (overrides = {}) => {
        const envVars = {};
        for (const key in process.env) {
            if (key.startsWith('VITE_')) {
                envVars[key] = process.env[key];
            }
        }
        return JSON.stringify({...envVars, ...overrides});
    };

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
            ...events.map((event) => ({loc: `${origin}/event/${event.id}/${event.slug}`, priority: '0.9', changefreq: 'daily'})),
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

    app.get('/llms.txt', async (req, res, next) => {
        if (await resolveCustomDomain(normalizeHost(req.get('host')))) {
            return next();
        }
        const {renderLlmsTxt} = await loadServerEntry();
        res.setHeader('Content-Type', 'text/plain; charset=utf-8');
        res.setHeader('Cache-Control', 'public, max-age=3600');
        res.status(200).send(renderLlmsTxt(platformBaseUrl(req)));
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
    app.get('/sitemap-events-:page.xml', sitemapEventsHandler);
    app.get('/sitemap-organizers-:page.xml', sitemapOrganizersHandler);

    app.use("*", async (req, res) => {
        let url = req.originalUrl.replace(base, "");

        delete req.headers[CUSTOM_DOMAIN_HEADER];
        const requestHost = normalizeHost(req.get('host'));
        const customDomain = await resolveCustomDomain(requestHost);

        if (customDomain) {
            if (PLATFORM_ONLY_PATHS.test(req.originalUrl) && platformUrl) {
                res.redirect(302, `${platformUrl}${req.originalUrl}`);
                return;
            }

            const [pathname, search] = req.originalUrl.split('?');
            if (new RegExp(`^/events/${customDomain.id}/[^/]+/?$`).test(pathname)) {
                res.redirect(302, `/${search ? `?${search}` : ''}`);
                return;
            }

            const sitePageMatch = pathname.match(new RegExp(`^/events/${customDomain.id}/[^/]+/(a-propos|evenements|services|partenaires|contact)/?$`));
            if (sitePageMatch) {
                res.redirect(302, `/${sitePageMatch[1]}${search ? `?${search}` : ''}`);
                return;
            }

            if (!req.cookies?.locale && !req.headers['accept-language']) {
                req.headers['accept-language'] = 'fr';
            }

            req.headers[CUSTOM_DOMAIN_HEADER] = JSON.stringify(customDomain);
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

            const { appHtml, dehydratedState, helmetContext, locale, htmlLang } = await render(
                { req, res },
                ssrManifest
            );
            const stringifiedState = JSON.stringify(dehydratedState);

            const helmetHtml = Object.values(helmetContext.helmet || {})
                .map((value) => value.toString() || "")
                .join(" ");

            const envVariablesHtml = `<script>window.hievents = ${getViteEnvironmentVariables(
                customDomain ? {VITE_FRONTEND_URL: `https://${requestHost}`} : {}
            )};window.__CUSTOM_DOMAIN_ORGANIZER__ = ${JSON.stringify(customDomain).replace(/</g, '\\u003c')};</script>`;

            const headSnippets = [];
            if (process.env.VITE_FATHOM_SITE_ID) {
                headSnippets.push(`
                <script src="https://cdn.usefathom.com/script.js" data-spa="auto" data-site="${process.env.VITE_FATHOM_SITE_ID}" defer></script>
            `);
            }

            const html = template
                .replace('<html lang="en">', `<html lang="${htmlLang}" data-locale="${locale}">`)
                .replace("<!--head-snippets-->", headSnippets.join("\n"))
                .replace("<!--app-html-->", appHtml)
                .replace("<!--dehydrated-state-->", `<script>window.__REHYDRATED_STATE__ = ${stringifiedState}</script>`)
                .replace("<!--environment-variables-->", envVariablesHtml)
                .replace(/<!--render-helmet-->.*?<!--\/render-helmet-->/s, helmetHtml);

            res.setHeader("Content-Type", "text/html");
            return res.status(200).end(html);
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