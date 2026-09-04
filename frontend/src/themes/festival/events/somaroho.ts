import { FestivalEventData } from "../types/festivalTheme.types";

/**
 * Configuration Somaroho Festival 2026.
 * Premier événement du thème "Festival" — sert de référence pour
 * la structure attendue par tout futur événement (Sanaa, concert...).
 */
export const somarohoEvent: FestivalEventData = {
    slug: "somaroho-2026",
    eventName: "Somaroho Festival",
    edition: "13e édition",
    tagline: "Le plus grand festival de Nosy Be",
    startDate: "2026-08-05",
    endDate: "2026-08-09",
    heroImageUrl: "/assets/festival/somaroho/hero.jpeg",

    venue: {
        name: "Stade Ambodivoanio",
        address: "Nosy Be, Madagascar",
        openingHours: "Ouverture des portes à 13h chaque jour",
        accessInfo: "Parking disponible sur place, navettes depuis le centre-ville",
    },

    days: [
        {
            id: "day-1",
            dayLabel: "Mer. 5 Août",
            date: "2026-08-05",
            title: "Grand Carnaval — Bal d'ouverture",
            headliners: [
                { id: "shenseea", name: "Shenseea" },
                { id: "wawa", name: "Wawa" },
            ],
            lineup: [
                { id: "theo-rakotovao", name: "Theo Rakotovao" },
                { id: "anatal", name: "Anatal" },
                { id: "b-junior", name: "B Junior" },
            ],
            price: 50000,
            currency: "MGA",
            colorAccent: "#C23FA1",
        },
        {
            id: "day-2",
            dayLabel: "Jeu. 6 Août",
            date: "2026-08-06",
            title: "Zily",
            headliners: [{ id: "zily", name: "Zily" }],
            lineup: [
                { id: "jior-shy", name: "Jior Shy" },
                { id: "kesy", name: "Kesy" },
                { id: "babalahy", name: "Babalahy" },
                { id: "yes-gah", name: "Yes Gah" },
                { id: "harena", name: "Harena" },
                { id: "jam-jued", name: "Jam Jued" },
                { id: "rijade", name: "Rijade" },
                { id: "maman-i-tsololo", name: "Maman'i Tsololo" },
            ],
            price: 20000,
            currency: "MGA",
            colorAccent: "#1AA398",
        },
        {
            id: "day-3",
            dayLabel: "Ven. 7 Août",
            date: "2026-08-07",
            title: "Shenseea",
            headliners: [{ id: "shenseea-2", name: "Shenseea" }],
            lineup: [
                { id: "tukson", name: "Tukson" },
                { id: "djino", name: "Djino" },
                { id: "ydah", name: "Ydah" },
                { id: "tence-mena", name: "Tence Mena" },
            ],
            price: 25000,
            currency: "MGA",
            colorAccent: "#E0473F",
        },
        {
            id: "day-4",
            dayLabel: "Sam. 8 Août",
            date: "2026-08-08",
            title: "Basta Lion",
            headliners: [{ id: "basta-lion", name: "Basta Lion" }],
            lineup: [
                { id: "rodman", name: "Rodman" },
                { id: "parish", name: "Parish" },
                { id: "rj", name: "RJ" },
                { id: "lc-barbo-jalengo", name: "LC Barbo Jalengo" },
                { id: "wendy-cathalina", name: "Wendy Cathalina" },
                { id: "destyn-maloya", name: "Destyn Maloya" },
            ],
            price: 20000,
            currency: "MGA",
            colorAccent: "#7B4FE0",
        },
        {
            id: "day-5",
            dayLabel: "Dim. 9 Août",
            date: "2026-08-09",
            title: "Wawa",
            headliners: [{ id: "wawa-2", name: "Wawa" }],
            lineup: [
                { id: "stella-lyncha", name: "Stella Lyncha" },
                { id: "lowis", name: "Lowis" },
                { id: "tchi-ur-ache", name: "Tchi Ur Ache" },
                { id: "chaldi", name: "Chaldi" },
                { id: "theo-rakotovao-2", name: "Theo Rakotovao" },
            ],
            price: 20000,
            currency: "MGA",
            colorAccent: "#B5651D",
        },
    ],

    sponsors: [
        { id: "thb", name: "THB" },
        { id: "yas", name: "Yas" },
        { id: "wawa-prod", name: "Wawa Production" },
        { id: "sw-prod", name: "SW Prod" },
        { id: "bet261", name: "Bet261" },
        { id: "rotsy-fm", name: "Rotsy.fm" },
    ],

    faq: [
        {
            id: "faq-1",
            question: "Puis-je acheter mon billet sur place ?",
            answer: "Nous recommandons l'achat en ligne pour garantir votre place, les quantités étant limitées par jour.",
        },
        {
            id: "faq-2",
            question: "Le billet donne-t-il accès à toute la journée ?",
            answer: "Oui, votre billet est valable pour l'intégralité de la programmation du jour choisi.",
        },
        {
            id: "faq-3",
            question: "Puis-je me faire rembourser ?",
            answer: "Les conditions de remboursement sont précisées dans les CGV disponibles au moment de l'achat.",
        },
    ],

    stats: [
        { label: "Jours", value: "5" },
        { label: "Artistes", value: "20+" },
        { label: "Édition", value: "13e" },
        { label: "Lieu", value: "Nosy Be" },
    ],

    hiEventsEventId: 4,
};
