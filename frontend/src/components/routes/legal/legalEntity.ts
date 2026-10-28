/* eslint-disable lingui/no-unlocalized-strings */

/**
 * Informations légales de l'éditeur, reprises dans toutes les pages /legal/*.
 * mediator reste null tant qu'aucun médiateur de la consommation n'est désigné.
 */
export const legalEntity = {
    brand: "PICHA Ticket",
    companyName: "DIGIT",
    legalForm: "SAS",
    shareCapital: "500 €",
    address: "1 rue Citronnelle, BP 66, 97660 Dembeni, Mayotte",
    registration: "RCS Mamoudzou 897 877 122 (SIRET 897 877 122 00019)",
    siren: "897 877 122",
    vatNumber: "FR63897877122",
    phone: "06 39 78 07 73",
    email: "direction@picha.fr",
    publicationDirector: "Josué Koutala, Président",
    privacyContactEmail: "ticket@picha.fr",
    mediator: null as null | {name: string, address: string, website: string},
    host: {
        name: "OVH SAS",
        address: "2 rue Kellermann, 59100 Roubaix, France",
        website: "https://www.ovhcloud.com",
    },
    lastUpdated: "28 octobre 2026",
};
