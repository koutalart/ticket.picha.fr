/* eslint-disable lingui/no-unlocalized-strings */
import {caseStudies, getCaseStudyTitle} from "../components/routes/case-studies/caseStudies.ts";
import {solutionPath, solutions} from "../components/routes/solutions/solutions.ts";

export interface MarketingPage {
    path: string;
    title: string;
    description: string;
    group: "home" | "solutions" | "case-studies";
    lastModified?: string;
}

export const getMarketingPages = (): MarketingPage[] => {
    const publishedCaseStudies = caseStudies.filter((caseStudy) => !caseStudy.draft);

    return [
        {
            path: "/",
            title: "PICHA Ticket : logiciel de gestion d'événements professionnels",
            description: "Invitations, inscriptions, contrôle d'accès par QR code, badges imprimés sur place, bracelets QR code personnalisés, émargement numérique et reporting. Formule accompagnée le jour J ou location de matériel. Hébergé en France, conforme au RGPD.",
            group: "home",
        },
        ...solutions.map((solution) => ({
            path: solutionPath(solution.slug),
            title: solution.name,
            description: solution.metaDescription,
            group: "solutions" as const,
        })),
        ...(publishedCaseStudies.length > 0 ? [{
            path: "/cas-clients",
            title: "Cas clients",
            description: "Festival, concert et journée portes ouvertes : billetterie, inscriptions et contrôle d'accès par QR code avec PICHA Ticket.",
            group: "case-studies" as const,
            lastModified: publishedCaseStudies
                .map((caseStudy) => caseStudy.updatedAt ?? caseStudy.publishedAt)
                .filter((date): date is string => !!date)
                .sort()
                .pop(),
        }] : []),
        ...publishedCaseStudies.map((caseStudy) => ({
            path: `/cas-clients/${caseStudy.slug}`,
            title: caseStudy.headline ?? getCaseStudyTitle(caseStudy),
            description: caseStudy.summary ?? getCaseStudyTitle(caseStudy),
            group: "case-studies" as const,
            lastModified: caseStudy.updatedAt ?? caseStudy.publishedAt,
        })),
    ];
};

const escapeXml = (value: string) => value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");

export const renderMarketingSitemap = (baseUrl: string): string => {
    const urls = getMarketingPages()
        .map((page) => {
            const lastModified = page.lastModified ? `<lastmod>${page.lastModified}</lastmod>` : "";
            return `  <url><loc>${escapeXml(`${baseUrl}${page.path}`)}</loc>${lastModified}</url>`;
        })
        .join("\n");

    return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls}\n</urlset>\n`;
};

export const renderLlmsTxt = (baseUrl: string): string => {
    const pages = getMarketingPages();
    const [home] = pages;
    const section = (group: MarketingPage["group"]) => pages
        .filter((page) => page.group === group)
        .map((page) => `- [${page.title}](${baseUrl}${page.path}): ${page.description}`)
        .join("\n");
    const caseStudiesSection = section("case-studies");

    return [
        "# PICHA Ticket",
        "",
        `> ${home.description}`,
        "",
        "PICHA Ticket est une plateforme d'accueil événementiel pour les entreprises, les institutions et collectivités, les associations et les réseaux professionnels. Deux formules : accompagnement le jour J avec des agents connectés, ou autonomie avec location de tablettes, scanners et imprimantes de badges.",
        "",
        "## Solutions",
        "",
        section("solutions"),
        ...(caseStudiesSection ? ["", "## Cas clients", "", caseStudiesSection] : []),
        "",
        "## Contact",
        "",
        `- [Planifier une démo](${baseUrl}/#demo): réponse sous 1 jour ouvré`,
        "- E-mail : ticket@picha.fr",
        "",
    ].join("\n");
};
