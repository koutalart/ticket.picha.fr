import {Product, ProductPrice, ProductType} from "../types.ts";
import {Constants} from "../constants.ts";
import type {ProductFormValue} from "../api/order.client.ts";

export const isRequiredPerTicket = (product: Product): boolean =>
    !!product.is_required && product.required_quantity_mode === 'PER_TICKET';

export const getRequiredProductQuantity = (product: Product, ticketQuantity: number): number =>
    isRequiredPerTicket(product)
        ? Math.max(0, ticketQuantity)
        : Math.max(1, Number(product.min_per_order) || 0);

const getRequiredPriceIndex = (product: Product): number =>
    (product.prices ?? []).findIndex(price => price.is_available !== false && !price.is_sold_out);

export const isEnforceableRequiredProduct = (product: Product): boolean =>
    !!product.is_required
    && product.is_available !== false
    && !product.is_sold_out
    && getRequiredPriceIndex(product) >= 0;

const getForcedQuantity = (product: Product, ticketQuantity: number): number => {
    const price = product.prices?.[getRequiredPriceIndex(product)];
    const limits = [price?.quantity_remaining, product.quantity_available]
        .filter((limit): limit is number => typeof limit === 'number' && limit !== Constants.INFINITE_TICKETS);

    return Math.max(0, Math.min(getRequiredProductQuantity(product, ticketQuantity), ...limits));
};

/**
 * Required products follow the tickets: when at least one ticket is selected they are
 * added with their forced quantity (fixed per order, or one per ticket), otherwise
 * they go back to 0. `products` and `values` share the same order.
 */
export const applyRequiredProducts = (products: Product[], values: ProductFormValue[] | undefined): ProductFormValue[] | undefined => {
    if (!values?.length || !products.some(isEnforceableRequiredProduct)) {
        return values;
    }

    const ticketQuantity = products.reduce((total, product, index) => {
        if (product.product_type !== ProductType.Ticket || isEnforceableRequiredProduct(product)) {
            return total;
        }
        return total + (values[index]?.quantities ?? []).reduce((sum, {quantity}) => sum + Number(quantity || 0), 0);
    }, 0);

    let changed = false;
    const next = values.map((value, index) => {
        const product = products[index];
        if (!product || !isEnforceableRequiredProduct(product) || Number(value.product_id) !== Number(product.id)) {
            return value;
        }

        const priceIndex = getRequiredPriceIndex(product);
        const forcedQuantity = ticketQuantity > 0 ? getForcedQuantity(product, ticketQuantity) : 0;
        const quantities = value.quantities.map((quantityValue, quantityIndex) => {
            const quantity = quantityIndex === priceIndex ? forcedQuantity : 0;
            if (Number(quantityValue.quantity) === quantity) {
                return quantityValue;
            }
            changed = true;
            return {...quantityValue, quantity};
        });

        return {...value, quantities};
    });

    return changed ? next : values;
};

export const getRequiredProductSelection = (product: Product, ticketQuantity: number): { price: ProductPrice, quantity: number } | null => {
    if (!isEnforceableRequiredProduct(product) || ticketQuantity <= 0) {
        return null;
    }
    const price = product.prices?.[getRequiredPriceIndex(product)];
    const quantity = getForcedQuantity(product, ticketQuantity);

    return price && quantity > 0 ? {price, quantity} : null;
};
