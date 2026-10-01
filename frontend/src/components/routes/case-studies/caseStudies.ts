/* eslint-disable lingui/no-unlocalized-strings */
import {SolutionSlug} from "../solutions/slugs.ts";

export type CaseStudyService =
    | "invitations"
    | "registrations"
    | "access-control"
    | "badges"
    | "wristbands"
    | "attendance-sheet"
    | "on-site-staff"
    | "equipment-rental";

export interface CaseStudyResult {
    value: string;
    label: string;
}

export interface CaseStudyQuote {
    text: string;
    author: string;
    role: string;
    approved: boolean;
}

export interface CaseStudyPhoto {
    src: string;
    alt: string;
}

export interface CaseStudy {
    slug: string;
    client: string;
    eventName: string;
    draft: boolean;
    sector?: string;
    date?: string;
    location?: string;
    attendees?: string;
    summary?: string;
    challenge?: string;
    solution?: string[];
    results?: CaseStudyResult[];
    headline?: string;
    dayOf?: string;
    eventStartDate?: string;
    eventEndDate?: string;
    eventLocality?: string;
    eventCountryCode?: string;
    publishedAt?: string;
    quote?: CaseStudyQuote;
    photos?: CaseStudyPhoto[];
    services: CaseStudyService[];
    solutions?: SolutionSlug[];
    coverImage?: string;
    coverAlt?: string;
}

export const serviceLabels: Record<CaseStudyService, string> = {
    "invitations": "Invitations",
    "registrations": "Inscriptions en ligne",
    "access-control": "Contrôle d'accès QR code",
    "badges": "Badges imprimés sur place",
    "wristbands": "Bracelets QR code personnalisés",
    "attendance-sheet": "Émargement numérique",
    "on-site-staff": "Agents d'accueil le jour J",
    "equipment-rental": "Location de matériel",
};

export const caseStudies: CaseStudy[] = [
    {
        slug: "somaroho",
        client: "Somaroho Festival",
        eventName: "Édition 2026",
        sector: "Festival",
        draft: false,
        date: "Du 5 au 9 août 2026",
        location: "Nosy Be, Madagascar",
        attendees: "93 020 bracelets",
        summary: "Cinq jours de festival, 93 020 bracelets QR code et 13 agents à l'accueil : le Somaroho Festival a confié le contrôle de ses accès à PICHA Ticket, avec une validation de chaque entrée en temps réel.",
        challenge: "Le Somaroho Festival devait accueillir plusieurs dizaines de milliers de participants sur cinq journées. L'enjeu : contrôler rapidement chaque bracelet à l'entrée, éviter les erreurs et les fraudes, et donner aux équipes terrain une vision claire des accès à tout moment.",
        solution: [
            "93 020 bracelets QR code générés pour le lot principal",
            "Bracelets sécurisés : identifiant unique et signature pour chaque bracelet",
            "Scanner dédié, utilisable depuis le navigateur d'un smartphone, sans installation",
            "13 agents mobilisés à l'accueil",
            "Contrôle et validation de chaque bracelet en temps réel",
            "Suivi des statuts de chaque bracelet : attribué, utilisé, révoqué",
        ],
        results: [
            {value: "93 020", label: "bracelets émis et contrôlés"},
            {value: "5", label: "jours de festival"},
            {value: "13", label: "agents à l'accueil"},
            {value: "0", label: "fraude constatée"},
        ],
        quote: {
            text: "Avec plus de 93 000 bracelets à contrôler sur cinq jours, il nous fallait un système fiable. Grâce à PICHA Ticket, nos agents ont validé chaque entrée en temps réel, et nous n'avons constaté aucune fraude de tout le festival.",
            author: "Djamilah",
            role: "organisatrice du Somaroho Festival",
            approved: false,
        },
        services: ["wristbands", "access-control", "on-site-staff"],
        solutions: ["controle-acces-qr-code"],
        headline: "Comment le Somaroho Festival a contrôlé 93 020 bracelets QR code avec PICHA Ticket",
        eventStartDate: "2026-08-05",
        eventEndDate: "2026-08-09",
        eventLocality: "Nosy Be",
        eventCountryCode: "MG",
        publishedAt: "2026-10-01",
    },
    {
        slug: "innocent-event",
        client: "Innocent Event",
        eventName: "Innocent Event",
        draft: true,
        services: [],
    },
    {
        slug: "mayotte-la-1ere-inauguration",
        client: "Mayotte la 1ère",
        eventName: "Inauguration",
        sector: "Média",
        draft: true,
        services: [],
    },
    {
        slug: "mayotte-la-1ere-journee-portes-ouvertes",
        client: "Mayotte la 1ère",
        eventName: "Journée portes ouvertes",
        sector: "Média",
        draft: true,
        services: [],
    },
    {
        slug: "cci-forum-de-l-industrie",
        client: "CCI",
        eventName: "Forum de l'industrie",
        sector: "Chambre de commerce et d'industrie",
        draft: true,
        services: [],
    },
];

export const isCaseStudyVisible = (caseStudy: CaseStudy): boolean => !caseStudy.draft || import.meta.env.DEV;

export const getVisibleCaseStudies = (): CaseStudy[] => caseStudies.filter(isCaseStudyVisible);

export const getCaseStudiesForSolution = (slug: SolutionSlug): CaseStudy[] =>
    getVisibleCaseStudies().filter((caseStudy) => caseStudy.solutions?.includes(slug));

export const getCaseStudyTitle = (caseStudy: CaseStudy): string =>
    caseStudy.client === caseStudy.eventName ? caseStudy.client : `${caseStudy.client} : ${caseStudy.eventName}`;
