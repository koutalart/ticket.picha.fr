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
    | "equipment-rental"
    | "influencer-campaign"
    | "online-ticketing"
    | "physical-presales"
    | "on-site-sales";

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
    updatedAt?: string;
    ticketingEventId?: number;
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
    "influencer-campaign": "Campagne influenceurs",
    "online-ticketing": "Billetterie en ligne",
    "physical-presales": "Préventes physiques",
    "on-site-sales": "Vente sur place le jour J",
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
        summary: "93 020 bracelets QR code contrôlés en cinq jours par 13 agents : le Somaroho Festival, à Nosy Be, a confié le contrôle de ses accès à PICHA Ticket.",
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
        eventName: "Triangle des Bermudes",
        sector: "Concerts et soirées",
        draft: false,
        ticketingEventId: 4,
        date: "Dimanche 11 octobre 2026, à partir de 18 h",
        location: "Le 5/5, rond-point de la Barge, Mamoudzou (Mayotte)",
        attendees: "886 billets vendus au 10 octobre",
        headline: "Billetterie de concert à Mayotte : 886 billets vendus en 10 jours pour Triangle des Bermudes",
        summary: "Billetterie en ligne, préventes au 5/5 et campagne influenceurs : Innocent Event a vendu 886 billets en 10 jours pour son concert à Mamoudzou.",
        challenge: "Innocent Event organise des concerts et des soirées à Mayotte. Pour Triangle des Bermudes, le dimanche 11 octobre 2026 au 5/5 à Mamoudzou, l'organisateur devait vendre vite sur deux canaux à la fois, en ligne et en préventes physiques, sans jamais vendre deux fois la même place. Le jour J, il fallait aussi faire entrer le public rapidement et continuer à vendre des billets sur place.",
        solution: [
            "Campagne influenceurs pour lancer la billetterie et relayer chaque palier de vente",
            "Billetterie en ligne sur le site de l'organisateur, innocent976.yt, avec paiement par carte bancaire",
            "Préventes physiques au 5/5 : billets émis par lots, décomptés du même stock que la vente en ligne",
            "Une offre « Vente flash » à quantité limitée, fermée automatiquement une fois épuisée",
            "Billet QR code envoyé par e-mail à chaque acheteur",
            "Contrôle d'accès par scan du QR code à l'entrée le jour J",
            "Vente de billets sur place le soir du concert",
        ],
        results: [
            {value: "886", label: "billets vendus en 10 jours"},
            {value: "486", label: "billets vendus en ligne, par carte bancaire"},
            {value: "400", label: "billets écoulés en préventes physiques au 5/5"},
            {value: "292", label: "places « Vente flash » : offre épuisée"},
        ],
        services: ["influencer-campaign", "online-ticketing", "physical-presales", "access-control", "on-site-sales"],
        solutions: ["controle-acces-qr-code"],
        eventStartDate: "2026-10-11T18:00:00+03:00",
        eventEndDate: "2026-10-12T00:30:00+03:00",
        eventLocality: "Mamoudzou",
        eventCountryCode: "YT",
        publishedAt: "2026-10-07",
        updatedAt: "2026-10-10",
    },
    {
        slug: "mayotte-la-1ere-journee-portes-ouvertes",
        client: "Mayotte la 1ère",
        eventName: "Journée portes ouvertes",
        sector: "Média",
        draft: false,
        ticketingEventId: 6,
        date: "Dimanche 18 octobre 2026, de 10 h à 16 h",
        location: "Mayotte la 1ère, Mayotte",
        attendees: "1 500 places, entrée libre sur inscription",
        headline: "Journée portes ouvertes de Mayotte la 1ère : inscriptions gratuites et accueil par QR code pour ses 40 ans",
        summary: "Pour ses 40 ans, Mayotte la 1ère ouvre ses portes le 18 octobre 2026 : inscription gratuite en ligne, billet QR code par e-mail et contrôle d'accès à l'entrée.",
        challenge: "Mayotte la 1ère, la chaîne de France Télévisions à Mayotte, fête ses 40 ans en ouvrant ses portes au public le dimanche 18 octobre 2026, de 10 h à 16 h. Au programme : un voyage dans les années 80 et plusieurs villages thématiques (médias et coulisses, souvenirs, influenceurs, saveurs gourmandes), sous le thème « Le salouva vous va si bien ». L'entrée est libre, mais la chaîne voulait connaître à l'avance le nombre de visiteurs, respecter une jauge de 1 500 personnes et fluidifier l'accueil le jour J.",
        solution: [
            "Page d'inscription gratuite en ligne, sans paiement, accessible depuis un smartphone",
            "Jauge de 1 500 places, fermée automatiquement une fois atteinte",
            "Billet QR code envoyé par e-mail à chaque inscrit",
            "Contrôle d'accès par scan du QR code à l'entrée le jour J",
            "Suivi des inscriptions et des entrées en temps réel pour les équipes de la chaîne",
        ],
        services: ["registrations", "access-control"],
        solutions: ["controle-acces-qr-code", "emargement-numerique"],
        eventStartDate: "2026-10-18T10:00:00+03:00",
        eventEndDate: "2026-10-18T16:00:00+03:00",
        eventCountryCode: "YT",
        publishedAt: "2026-10-07",
        updatedAt: "2026-10-07",
    },
];

export const isCaseStudyVisible = (caseStudy: CaseStudy): boolean => !caseStudy.draft || import.meta.env.DEV;

export const getVisibleCaseStudies = (): CaseStudy[] => caseStudies.filter(isCaseStudyVisible);

export const getCaseStudiesForSolution = (slug: SolutionSlug): CaseStudy[] =>
    getVisibleCaseStudies().filter((caseStudy) => caseStudy.solutions?.includes(slug));

export const getCaseStudyTitle = (caseStudy: CaseStudy): string =>
    caseStudy.client === caseStudy.eventName ? caseStudy.client : `${caseStudy.client} : ${caseStudy.eventName}`;
