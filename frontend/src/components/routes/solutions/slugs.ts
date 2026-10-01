export const SOLUTION_SLUGS = [
    "emargement-numerique",
    "impression-badges-evenement",
    "controle-acces-qr-code",
    "logiciel-evenement-collectivites",
    "location-materiel-evenementiel",
] as const;

export type SolutionSlug = typeof SOLUTION_SLUGS[number];
