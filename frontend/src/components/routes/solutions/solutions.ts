/* eslint-disable lingui/no-unlocalized-strings */
import {SolutionSlug} from "./slugs.ts";

export type {SolutionSlug};

export type SolutionCtaTarget = "demo" | "shop" | "how";

export interface SolutionCta {
    label: string;
    target: SolutionCtaTarget;
}

export interface SolutionSection {
    title: string;
    text?: string;
    items?: string[];
    steps?: { title: string; text: string }[];
}

export interface SolutionFaq {
    question: string;
    answer: string;
}

export interface Solution {
    slug: SolutionSlug;
    name: string;
    metaTitle: string;
    metaDescription: string;
    headline: string;
    intro: string;
    primaryCta: SolutionCta;
    sections: SolutionSection[];
    faq: SolutionFaq[];
    finalTitle: string;
    finalCta: SolutionCta;
}

export const solutions: Solution[] = [
    {
        slug: "emargement-numerique",
        name: "Émargement numérique",
        metaTitle: "Émargement numérique pour vos événements | PICHA Ticket",
        metaDescription: "Suivez les présences de vos événements en temps réel avec l'émargement numérique PICHA Ticket : QR code, liste des présents et export des feuilles d'émargement.",
        headline: "L'émargement numérique qui vous donne une vision claire de vos présences.",
        intro: "Finies les listes papier à relire et les feuilles d'émargement à ressaisir. PICHA Ticket enregistre chaque arrivée grâce à un QR code unique et vous donne, en temps réel, une liste fiable des participants présents.",
        primaryCta: {label: "Planifier ma démo", target: "demo"},
        sections: [
            {
                title: "Des présences fiables, sans ressaisie",
                text: "Chaque participant reçoit un billet nominatif avec son QR code. À son arrivée, votre équipe scanne le code : l'entrée est enregistrée immédiatement. Vous savez qui est présent et à quelle heure il est arrivé.",
                items: [
                    "Liste des participants actualisée en temps réel",
                    "Feuille d'émargement exportable après l'événement",
                    "Inscription sur place pour les participants de dernière minute",
                    "Adapté aux conférences, assemblées générales, formations et événements internes",
                ],
            },
            {
                title: "Un parcours d'accueil en trois gestes",
                steps: [
                    {title: "Inviter", text: "Envoyez vos invitations ou ouvrez les inscriptions en ligne."},
                    {title: "Scanner", text: "Vérifiez l'accès du participant grâce à son QR code."},
                    {title: "Exporter", text: "Récupérez votre feuille d'émargement dès la fin de l'événement."},
                ],
            },
            {
                title: "Pour vos événements où la présence compte",
                text: "Conférence, formation, assemblée générale ou cérémonie : vous disposez d'un suivi de présence exploitable, sans ralentir l'accueil.",
            },
        ],
        faq: [
            {
                question: "L'émargement numérique fonctionne-t-il sans application pour les participants ?",
                answer: "Oui. Le participant présente le billet reçu par e-mail, avec son QR code ; l'équipe d'accueil le scanne avec le matériel prévu pour l'événement.",
            },
            {
                question: "Peut-on inscrire une personne sur place ?",
                answer: "Oui. Les participants de dernière minute sont ajoutés depuis une tablette, puis intégrés au suivi des présences.",
            },
            {
                question: "Puis-je récupérer une feuille d'émargement ?",
                answer: "Oui. La feuille d'émargement est exportable après l'événement.",
            },
        ],
        finalTitle: "Voyez vos présences se mettre à jour en direct.",
        finalCta: {label: "Planifier ma démo", target: "demo"},
    },
    {
        slug: "impression-badges-evenement",
        name: "Impression de badges",
        metaTitle: "Impression de badges événementiels sur site | PICHA Ticket",
        metaDescription: "Imprimez les badges de vos participants à leur arrivée : QR code, contrôle d'accès et impression sur site avec PICHA Ticket.",
        headline: "Des badges imprimés au bon moment. Un accueil qui avance sans attente.",
        intro: "À l'arrivée, le QR code du participant est scanné, son accès est validé et son badge s'imprime sur place. Un parcours simple pour vos invités, un accueil maîtrisé pour vos équipes.",
        primaryCta: {label: "Planifier ma démo", target: "demo"},
        sections: [
            {
                title: "Un badge pour chaque participant, dès son arrivée",
                text: "PICHA Ticket réunit l'inscription, le contrôle d'accès et l'impression du badge dans un même parcours. Vos équipes n'ont plus à chercher dans une liste ; vos invités avancent plus vite.",
                items: [
                    "Badges imprimés sur site",
                    "Informations reprises de l'inscription du participant",
                    "Contrôle d'accès par QR code",
                    "Inscriptions de dernière minute depuis une tablette",
                    "Bracelets QR code à vos couleurs, avec votre logo",
                ],
            },
            {
                title: "Votre accueil, prêt avant l'ouverture",
                text: "Selon la formule choisie, PICHA Ticket vous accompagne dans le paramétrage, fournit le matériel et assure l'accueil le jour J. Vous choisissez le niveau d'autonomie qui convient à votre organisation.",
            },
            {
                title: "Un badge à l'image de votre événement",
                text: "Le badge est souvent le premier objet remis à vos invités. Choisissez les informations utiles à l'accueil : nom, organisation, rôle, accès ou ateliers.",
            },
        ],
        faq: [
            {
                question: "Les badges sont-ils imprimés avant ou pendant l'événement ?",
                answer: "Ils sont imprimés à l'arrivée, juste après la validation du participant par QR code.",
            },
            {
                question: "Pouvez-vous fournir le matériel ?",
                answer: "Oui. PICHA Ticket loue des tablettes, des scanners et des imprimantes de badges.",
            },
            {
                question: "Les personnes non inscrites peuvent-elles recevoir un badge ?",
                answer: "Oui. Elles sont ajoutées sur place depuis une tablette, puis accueillies dans le même parcours.",
            },
        ],
        finalTitle: "Faites de l'arrivée un moment fluide, pas un goulot d'étranglement.",
        finalCta: {label: "Parler de mon événement", target: "demo"},
    },
    {
        slug: "controle-acces-qr-code",
        name: "Contrôle d'accès QR code",
        metaTitle: "Contrôle d'accès par QR code pour événement | PICHA Ticket",
        metaDescription: "Contrôlez les accès de votre événement par QR code : billets nominatifs ou bracelets, validation des entrées et suivi des présences en temps réel avec PICHA Ticket.",
        headline: "Contrôlez les accès de votre événement avec un QR code unique.",
        intro: "Chaque participant reçoit un billet ou un bracelet avec son QR code. À l'entrée, un scan suffit pour vérifier son accès et enregistrer sa présence. L'accueil reste fluide, même quand les arrivées s'accélèrent.",
        primaryCta: {label: "Planifier ma démo", target: "demo"},
        sections: [
            {
                title: "Un contrôle d'accès qui ne ralentit pas l'événement",
                text: "Le contrôle d'accès ne doit pas créer de file d'attente. Avec un QR code unique par participant, l'équipe d'accueil valide les arrivées en quelques secondes et retrouve les informations utiles au même endroit.",
                items: [
                    "Billets envoyés par e-mail ou bracelets QR code personnalisés",
                    "QR code unique par participant",
                    "Validation des entrées en direct",
                    "Vue actualisée des inscrits et des présents",
                    "Inscription sur place si besoin",
                ],
            },
            {
                title: "Gardez une vision claire de vos entrées",
                text: "Suivez les arrivées tout au long de l'événement. Vous disposez d'une base fiable pour piloter l'accueil et faire le bilan des présences.",
            },
            {
                title: "Du séminaire au festival",
                text: "Séminaire d'entreprise, rencontre institutionnelle, événement sportif, assemblée générale ou festival : adaptez les inscriptions et les informations recueillies à votre format.",
            },
        ],
        faq: [
            {
                question: "Un QR code peut-il être utilisé plusieurs fois ?",
                answer: "Non. Chaque QR code est unique : dès le premier scan, l'entrée est enregistrée, et un second passage avec le même code est immédiatement signalé à l'agent.",
            },
            {
                question: "Les invités doivent-ils installer une application ?",
                answer: "Non. Le billet est envoyé par e-mail au participant, ou son bracelet lui est remis.",
            },
            {
                question: "Peut-on associer le QR code à un badge ?",
                answer: "Oui. Le scan valide l'accès et lance l'impression du badge au même moment.",
            },
        ],
        finalTitle: "Faites entrer vos participants, pas le stress.",
        finalCta: {label: "Planifier ma démo", target: "demo"},
    },
    {
        slug: "logiciel-evenement-collectivites",
        name: "Collectivités et institutions",
        metaTitle: "Logiciel de gestion d'événements pour collectivités | PICHA Ticket",
        metaDescription: "Gérez les inscriptions, l'accueil, l'émargement et les badges de vos événements institutionnels avec PICHA Ticket, hébergé en France.",
        headline: "Le logiciel événementiel pensé pour l'accueil de vos publics.",
        intro: "Conférences, assemblées générales, rencontres publiques, inaugurations ou événements sportifs : PICHA Ticket simplifie les inscriptions et l'accueil de vos participants, avec un suivi de présence fiable.",
        primaryCta: {label: "Échanger sur mon événement", target: "demo"},
        sections: [
            {
                title: "Préparez un accueil à la hauteur de votre événement",
                text: "Créez une page d'inscription, posez les questions utiles, envoyez vos invitations et centralisez les informations de vos participants. Le jour J, vos équipes suivent un parcours d'accueil clair, du scan à l'émargement.",
                items: [
                    "Formulaires d'inscription personnalisables",
                    "Invitations et billets avec QR code",
                    "Accueil, badges et inscriptions sur place",
                    "Émargement numérique et rapports de présence",
                    "Accompagnement sur place possible",
                ],
            },
            {
                title: "Des données hébergées en France",
                text: "Les données sont hébergées sur des serveurs situés en France, chez OVHcloud. PICHA Ticket agit en sous-traitant : l'organisateur reste responsable du traitement des données de ses participants.",
            },
            {
                title: "Deux manières de travailler avec PICHA Ticket",
                steps: [
                    {title: "Accompagnement le jour J", text: "Nos agents connectés et le matériel adapté épaulent vos équipes sur place."},
                    {title: "Autonomie", text: "Louez le matériel nécessaire et gérez l'accueil avec votre propre équipe."},
                ],
            },
        ],
        faq: [
            {
                question: "PICHA Ticket convient-il à une assemblée générale ?",
                answer: "Oui. La plateforme gère les inscriptions, l'accueil et le suivi de présence de ce type d'événement.",
            },
            {
                question: "Où sont stockées les données ?",
                answer: "En France, sur des serveurs OVHcloud.",
            },
            {
                question: "Pouvons-nous être accompagnés sur place ?",
                answer: "Oui. Une formule d'accompagnement avec des agents connectés est proposée selon les besoins de l'événement.",
            },
        ],
        finalTitle: "Parlez-nous de votre prochain événement public ou institutionnel.",
        finalCta: {label: "Planifier ma démo", target: "demo"},
    },
    {
        slug: "location-materiel-evenementiel",
        name: "Location de matériel",
        metaTitle: "Location de matériel pour accueil événementiel | PICHA Ticket",
        metaDescription: "Louez le matériel pour l'accueil de votre événement : tablettes, scanners et imprimantes de badges, avec la plateforme PICHA Ticket.",
        headline: "Louez le matériel dont vous avez besoin pour un accueil fluide.",
        intro: "Vous voulez garder la main sur l'accueil de votre événement ? PICHA Ticket vous donne accès à sa plateforme et vous loue le matériel prêt à l'emploi pour inscrire, scanner et accueillir vos participants.",
        primaryCta: {label: "Louer du matériel en ligne", target: "shop"},
        sections: [
            {
                title: "Votre équipe garde la main, votre accueil reste équipé",
                text: "Composez une formule adaptée à votre événement avec le matériel nécessaire à l'inscription, au contrôle d'accès et à l'impression des badges.",
                items: [
                    "Tablettes d'inscription",
                    "Scanners de QR codes",
                    "Imprimantes de badges",
                    "Accès à la plateforme PICHA Ticket",
                ],
            },
            {
                title: "Un parcours simple, de la préparation au retour du matériel",
                text: "Préparez votre événement sur la plateforme, accueillez vos participants avec votre équipe, puis récupérez vos données de présence pour le bilan.",
            },
            {
                title: "Pour les équipes qui veulent rester autonomes",
                text: "La location convient aux organisateurs qui ont leur propre équipe d'accueil et veulent s'équiper sans multiplier les prestataires.",
            },
        ],
        faq: [
            {
                question: "Que comprend la location ?",
                answer: "L'accès à la plateforme et, selon vos besoins, la location de tablettes, de scanners et d'imprimantes de badges.",
            },
            {
                question: "Puis-je proposer des inscriptions gratuites ?",
                answer: "Oui. Les inscriptions gratuites sont sans frais ; seuls les billets payants vendus sont soumis à des frais de 0,99 €.",
            },
            {
                question: "Puis-je être accompagné le jour J ?",
                answer: "Oui. Si vous préférez être accompagné, choisissez la formule avec agents connectés.",
            },
        ],
        finalTitle: "Équipez votre accueil sans compliquer votre organisation.",
        finalCta: {label: "Louer du matériel en ligne", target: "shop"},
    },
];

export const getSolution = (slug: string): Solution | undefined => solutions.find((solution) => solution.slug === slug);

export const solutionPath = (slug: SolutionSlug): string => `/${slug}`;
