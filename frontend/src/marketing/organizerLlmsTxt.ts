/* eslint-disable lingui/no-unlocalized-strings */
import {Event, Organizer} from "../types.ts";
import {getStructuredDataProducts, isEventFree} from "../utilites/eventStructuredData.ts";

export interface OrganizerLlmsTxtInput {
    origin: string;
    organizer: Organizer;
    events: Event[];
    customDomain: boolean;
}

const CUSTOM_DOMAIN_PAGES: Array<{ path: string; title: string; description: string }> = [
    {path: "/", title: "Accueil", description: "présentation et événement à la une"},
    {path: "/evenements", title: "Événements", description: "tous les événements publiés"},
    {path: "/a-propos", title: "À propos", description: "présentation de l'organisateur"},
    {path: "/partenaires", title: "Partenaires", description: "partenaires et soutiens"},
    {path: "/contact", title: "Contact", description: "formulaire de contact de l'organisateur"},
];

const PUBLIC_TEXT_REPLACEMENTS: Array<[RegExp, string]> = [
    [/[—–]/g, ","],
    [/[•·]/g, ","],
];

export const cleanPublicText = (value?: string | null): string => {
    if (!value) {
        return "";
    }
    let text = value
        .replace(/<\/(p|div|li|h[1-6])>/gi, " ")
        .replace(/<br\s*\/?>/gi, " ")
        .replace(/<[^>]*>/g, "")
        .replace(/&nbsp;/g, " ")
        .replace(/&amp;/g, "&")
        .replace(/&quot;/g, "\"")
        .replace(/&#39;|&apos;/g, "'")
        .replace(/&lt;/g, "<")
        .replace(/&gt;/g, ">");
    PUBLIC_TEXT_REPLACEMENTS.forEach(([pattern, replacement]) => {
        text = text.replace(pattern, replacement);
    });
    return text.replace(/\s+/g, " ").replace(/\s+,/g, ",").trim();
};

const truncate = (value: string, max: number) =>
    value.length > max ? `${value.slice(0, max - 3).replace(/\s+\S*$/, "")}...` : value;

const formatDate = (date: string, timeZone?: string): string => {
    try {
        return new Intl.DateTimeFormat("fr-FR", {
            timeZone: timeZone || "UTC",
            dateStyle: "full",
            timeStyle: "short",
        }).format(new Date(date.includes("T") || date.endsWith("Z") ? date : `${date.replace(" ", "T")}Z`));
    } catch {
        return date;
    }
};

const formatPrice = (amount: number, currency?: string): string => {
    try {
        return new Intl.NumberFormat("fr-FR", {style: "currency", currency: currency || "EUR"}).format(amount);
    } catch {
        return `${amount} ${currency || ""}`.trim();
    }
};

const eventPrice = (event: Event): string | null => {
    if (isEventFree(event)) {
        return "gratuit, sur inscription";
    }
    const prices = getStructuredDataProducts(event)
        .flatMap(product => product.prices || [])
        .map(price => Number(price.price))
        .filter(price => Number.isFinite(price) && price > 0);
    return prices.length ? `à partir de ${formatPrice(Math.min(...prices), event.currency)}` : null;
};

const eventLocation = (event: Event): string | null => {
    if (event.settings?.is_online_event) {
        return "en ligne";
    }
    const details = event.settings?.location_details;
    if (!details) {
        return null;
    }
    const parts = [
        details.venue_name,
        details.address_line_1,
        details.zip_or_postal_code && details.city ? `${details.zip_or_postal_code} ${details.city}` : details.city,
    ].filter(Boolean);
    return parts.length ? cleanPublicText(parts.join(", ")) : null;
};

const isIndexable = (event: Event) =>
    event.status === "LIVE" && event.settings?.allow_search_engine_indexing !== false;

export const renderOrganizerLlmsTxt = ({origin, organizer, events, customDomain}: OrganizerLlmsTxtInput): string => {
    const base = origin.replace(/\/$/, "");
    const organizerName = cleanPublicText(organizer.name);
    const organizerPage = customDomain ? `${base}/` : `${base}/events/${organizer.id}/${organizer.slug}`;
    const description = truncate(cleanPublicText(organizer.description), 400);
    const eventUrl = (event: Event) => `${base}/event/${event.id}/${event.slug}`;

    const pages = customDomain
        ? CUSTOM_DOMAIN_PAGES.map(page => `- [${page.title}](${base}${page.path}): ${page.description}`)
        : [`- [${organizerName}](${organizerPage}): page de l'organisateur et liste de ses événements`];

    const publishedEvents = events
        .filter(isIndexable)
        .sort((a, b) => new Date(a.start_date).getTime() - new Date(b.start_date).getTime());

    const eventSections = publishedEvents.map(event => {
        const url = eventUrl(event);
        const price = eventPrice(event);
        const location = eventLocation(event);
        const summary = truncate(cleanPublicText(event.settings?.seo_description || event.description_preview), 300);
        return [
            `### ${cleanPublicText(event.title)}`,
            "",
            ...(summary ? [summary, ""] : []),
            `- Date : ${formatDate(event.start_date, event.timezone)}${event.end_date ? `, jusqu'au ${formatDate(event.end_date, event.timezone)}` : ""}`,
            ...(location ? [`- Lieu : ${location}`] : []),
            ...(price ? [`- Prix : ${price}`] : []),
            `- Inscription : ${url}#tickets`,
            `- Page de l'événement : ${url}`,
        ].join("\n");
    });

    const contact = [
        ...(organizer.website ? [`- Site web : ${organizer.website}`] : []),
        customDomain
            ? `- Formulaire de contact : ${base}/contact`
            : `- Contact : bouton « Contact » sur ${organizerPage}`,
    ];

    return [
        `# ${organizerName}`,
        "",
        ...(description ? [`> ${description}`, ""] : []),
        `Billetterie et inscriptions officielles de ${organizerName}, propulsées par PICHA Ticket. Les informations ci-dessous sont mises à jour automatiquement à partir des événements publiés.`,
        "",
        "## Pages principales",
        "",
        ...pages,
        "",
        "## Événements à venir",
        "",
        ...(eventSections.length ? [eventSections.join("\n\n")] : ["Aucun événement publié pour le moment."]),
        "",
        "## Contact",
        "",
        ...contact,
        "",
    ].join("\n");
};
