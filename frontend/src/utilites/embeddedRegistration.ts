import {Event, Product, ProductPrice, Question, QuestionType, TaxAndFeeType} from "../types.ts";
import {Constants} from "../constants.ts";
import {getRequiredProductSelection} from "./requiredProducts.ts";
import {isCheckoutRefundProtection} from "./refundProtection.ts";

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
    && getEventProducts(event).length > 0;

const getFreeAvailablePrice = (product: Product): ProductPrice | undefined =>
    product.prices?.find(price => Number(price.price) <= 0 && price.is_available !== false && !price.is_sold_out);

export const getRegistrableProducts = (event?: Event): Product[] =>
    getEventProducts(event).filter(product =>
        product.product_type === 'TICKET'
        && !product.is_required
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

const DEFAULT_MAX_PER_ORDER = 25;

const isSelectablePrice = (price: ProductPrice) => price.is_available !== false && !price.is_sold_out;

const isFiniteLimit = (limit: unknown): limit is number =>
    typeof limit === 'number' && limit !== Constants.INFINITE_TICKETS;

export interface EmbeddedCheckoutLine {
    key: string;
    product: Product;
    price: ProductPrice;
    isTicket: boolean;
    max: number;
}

export interface EmbeddedCheckoutItem {
    key: string;
    product: Product;
    price: ProductPrice;
    quantity: number;
    isTicket: boolean;
    isRequired: boolean;
}

export const lineKey = (product: Product, price: ProductPrice) => `${product.id}:${price.id}`;

export const getProductMaxPerOrder = (product: Product): number =>
    Number(product.max_per_order) || DEFAULT_MAX_PER_ORDER;

export const getEmbeddedCheckoutLines = (event?: Event): EmbeddedCheckoutLine[] =>
    getEventProducts(event)
        .filter(product => !product.is_required && !isCheckoutRefundProtection(product) && product.is_available !== false && !product.is_sold_out)
        .flatMap(product => (product.prices ?? []).filter(isSelectablePrice).map(price => {
            const limits = [price.quantity_remaining, product.quantity_available].filter(isFiniteLimit);
            return {
                key: lineKey(product, price),
                product,
                price,
                isTicket: product.product_type === 'TICKET',
                max: Math.max(0, Math.min(getProductMaxPerOrder(product), ...limits)),
            };
        }))
        .filter(line => line.max > 0);

export const getRequiredCheckoutItems = (event: Event | undefined, ticketQuantity: number): EmbeddedCheckoutItem[] =>
    getEventProducts(event).flatMap(product => {
        const selection = isCheckoutRefundProtection(product) ? null : getRequiredProductSelection(product, ticketQuantity);
        return selection ? [{
            key: lineKey(product, selection.price),
            product,
            price: selection.price,
            quantity: selection.quantity,
            isTicket: product.product_type === 'TICKET',
            isRequired: true,
        }] : [];
    });

export const hasUnsupportedCheckoutProducts = (event?: Event): boolean =>
    !!event?.settings?.single_product_per_order
    || getEventProducts(event).some(product =>
        product.type === 'DONATION' && product.is_available !== false && !product.is_sold_out
    );

const roundMoney = (value: number) => Math.round(value * 100) / 100;

export interface EmbeddedCheckoutTotals {
    subtotal: number;
    fees: number;
    taxes: number;
    total: number;
    feeNames: string[];
}

export const computeCheckoutTotals = (items: EmbeddedCheckoutItem[]): EmbeddedCheckoutTotals => {
    const totals = items.reduce((sum, {product, price, quantity}) => {
        const unit = Number(price.price) || 0;
        const fee = Number(price.fee_total) || 0;
        const tax = Number(price.tax_total) || 0;
        const unitTotal = price.price_including_taxes_and_fees !== undefined && price.price_including_taxes_and_fees !== null
            ? Number(price.price_including_taxes_and_fees)
            : unit + fee + tax;
        if (fee > 0) {
            (product.taxes ?? [])
                .filter(tax => tax.type === TaxAndFeeType.Fee && tax.name)
                .forEach(tax => sum.feeNames.add(tax.name));
        }
        return {
            subtotal: sum.subtotal + unit * quantity,
            fees: sum.fees + fee * quantity,
            taxes: sum.taxes + tax * quantity,
            total: sum.total + unitTotal * quantity,
            feeNames: sum.feeNames,
        };
    }, {subtotal: 0, fees: 0, taxes: 0, total: 0, feeNames: new Set<string>()});

    return {
        subtotal: roundMoney(totals.subtotal),
        fees: roundMoney(totals.fees),
        taxes: roundMoney(totals.taxes),
        total: roundMoney(totals.total),
        feeNames: [...totals.feeNames],
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
