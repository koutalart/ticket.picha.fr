import {Event, Product, ProductPrice, Question, QuestionType} from "../types.ts";
import {getFullscreenHero} from "./fullscreenHero.ts";
import {Constants} from "../constants.ts";
import {eventHasRequiredProducts} from "./requiredProducts.ts";

export const SUPPORTED_EMBEDDED_QUESTION_TYPES: string[] = [
    QuestionType.SINGLE_LINE_TEXT,
    QuestionType.MULTI_LINE_TEXT,
    QuestionType.PHONE,
    QuestionType.DROPDOWN,
    QuestionType.RADIO,
    QuestionType.CHECKBOX,
    QuestionType.DATE,
];

export const getEventProducts = (event?: Event): Product[] =>
    event?.products || event?.product_categories?.flatMap(category => category.products || []) || [];

const isPaidProduct = (product: Product) =>
    product.type === 'DONATION' || !!product.prices?.some(price => Number(price.price) > 0);

export const isFreeEvent = (event?: Event): boolean => {
    const products = getEventProducts(event);
    return products.length > 0 && products.every(product => !isPaidProduct(product));
};

export const isEmbeddedRegistrationEnabled = (event?: Event): boolean =>
    !!event?.settings?.embedded_registration_enabled
    && getFullscreenHero(event).enabled
    && isFreeEvent(event)
    && !eventHasRequiredProducts(getEventProducts(event));

const getFreeAvailablePrice = (product: Product): ProductPrice | undefined =>
    product.prices?.find(price => Number(price.price) <= 0 && price.is_available !== false && !price.is_sold_out);

export const getRegistrableProducts = (event?: Event): Product[] =>
    getEventProducts(event).filter(product =>
        product.product_type === 'TICKET'
        && !isPaidProduct(product)
        && product.is_available !== false
        && !product.is_sold_out
        && !!getFreeAvailablePrice(product)
    );

export interface EmbeddedRegistrationTicket {
    product: Product;
    price: ProductPrice;
    minParticipants: number;
    maxParticipants: number;
    unlockedByCode: boolean;
}

export const resolveEmbeddedRegistrationTicket = (
    event: Event | undefined,
    publicProductIds: Set<number> | null,
): EmbeddedRegistrationTicket | null => {
    const products = getRegistrableProducts(event);
    const unlocked = publicProductIds
        ? products.filter(product => !publicProductIds.has(Number(product.id)))
        : [];
    const product = unlocked[0] ?? products[0];

    if (!product) {
        return null;
    }

    const price = getFreeAvailablePrice(product) as ProductPrice;
    const remaining = product.quantity_available ?? price.quantity_remaining;
    const perOrderMax = product.max_per_order || 25;
    const maxParticipants = remaining && remaining !== Constants.INFINITE_TICKETS
        ? Math.max(1, Math.min(perOrderMax, remaining))
        : perOrderMax;

    return {
        product,
        price,
        minParticipants: Math.min(Math.max(1, product.min_per_order || 1), maxParticipants),
        maxParticipants,
        unlockedByCode: unlocked.length > 0,
    };
};

export const sortQuestions = (questions: Question[]): Question[] =>
    questions
        .map((question, index) => ({question, index}))
        .sort((a, b) => (Number(a.question.order ?? 0) - Number(b.question.order ?? 0)) || a.index - b.index)
        .map(({question}) => question);

export const hasUnsupportedQuestions = (questions: Question[]): boolean =>
    questions.some(question => !SUPPORTED_EMBEDDED_QUESTION_TYPES.includes(question.type));

export const isSingleConsentQuestion = (question: Question): boolean =>
    question.type === QuestionType.CHECKBOX && (question.options?.length ?? 0) === 1;

export const PHONE_PATTERN = /^[0-9+\-\s()]{8,20}$/;
export const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export const isAnswerEmpty = (answer: unknown): boolean => {
    if (answer === null || answer === undefined) {
        return true;
    }
    if (Array.isArray(answer)) {
        return answer.length === 0;
    }
    return String(answer).trim() === '';
};
