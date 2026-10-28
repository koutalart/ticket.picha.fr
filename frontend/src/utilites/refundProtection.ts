import {Product, ProductCategory, ProductPrice} from "../types.ts";

/** Navigation state set by the embedded form when it already offered the option, so the payment page does not offer it twice. */
export const REFUND_PROTECTION_OFFERED_STATE = 'refundProtectionOffered';

/**
 * The cancellation protection (not sold as a ticket) is offered right before payment,
 * never in the product list. Mirrors OrderRefundProtectionService::isCheckoutRefundProtection.
 */
export const isCheckoutRefundProtection = (product: Pick<Product, 'is_refund_protection' | 'product_type'>): boolean =>
    !!product.is_refund_protection && product.product_type !== 'TICKET';

export const withoutCheckoutRefundProtection = <T extends ProductCategory>(categories: T[]): T[] =>
    categories
        .filter(category => !(category.products?.length && category.products.every(isCheckoutRefundProtection)))
        .map(category => category.products?.some(isCheckoutRefundProtection)
            ? {...category, products: category.products.filter(product => !isCheckoutRefundProtection(product))}
            : category);

/**
 * Unit amount of the mandatory cancellation protection that the order adds to each ticket of this price
 * (ticket above the platform threshold, or protection required per ticket by the organizer). Computed by the server.
 */
export const getIncludedRefundProtection = (price?: Pick<ProductPrice, 'refund_protection_included'> | null): number =>
    Math.max(0, Number(price?.refund_protection_included) || 0);

export const withIncludedRefundProtection = (amount: number, price?: Pick<ProductPrice, 'refund_protection_included'> | null): number =>
    Math.round((amount + getIncludedRefundProtection(price)) * 100) / 100;

/**
 * The protection offered right before payment, picked like OrderRefundProtectionService (first available by order).
 */
export const getCheckoutRefundProtection = (products: Product[]): { product: Product, price: ProductPrice } | null => {
    const candidates = products
        .filter(product => isCheckoutRefundProtection(product) && product.is_available !== false && !product.is_sold_out)
        .sort((a, b) => (Number(a.order ?? 0) - Number(b.order ?? 0)) || (Number(a.id) - Number(b.id)));

    for (const product of candidates) {
        const price = (product.prices ?? []).find(candidate =>
            Number(candidate.price) > 0 && candidate.is_available !== false && !candidate.is_sold_out
        );
        if (price) {
            return {product, price};
        }
    }

    return null;
};

/**
 * First sentence of a product description, as plain text (SSR safe, no DOM).
 */
export const getPlainTextSummary = (html?: string | null, maxLength = 140): string | null => {
    const text = String(html ?? '')
        .replace(/<\/(p|div|li)>|<br\s*\/?>/gi, ' ')
        .replace(/<[^>]*>/g, '')
        .replace(/&nbsp;/g, ' ')
        .replace(/&amp;/g, '&')
        .replace(/&#39;|&apos;/g, "'")
        .replace(/&quot;/g, '"')
        .replace(/\s+/g, ' ')
        .trim();

    if (!text) {
        return null;
    }

    const firstSentence = text.match(/^.+?[.!?](\s|$)/)?.[0]?.trim() ?? text;

    return firstSentence.length > maxLength ? `${firstSentence.slice(0, maxLength - 1).trimEnd()}...` : firstSentence;
};
