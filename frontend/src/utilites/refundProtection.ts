import {Product, ProductCategory} from "../types.ts";

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
