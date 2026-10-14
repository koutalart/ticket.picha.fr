import {Event, Product} from "../types.ts";
import {getProductsFromEvent} from "./helpers.ts";

export const isFreeProduct = (product: Product) => product.type === 'FREE'
    || (product.prices ?? []).every((price) => Number(price.price ?? 0) <= 0);

export const isFreeEvent = (event?: Event): boolean => {
    const tickets = (getProductsFromEvent(event) ?? []).filter((product) => product.product_type === 'TICKET');

    return tickets.length > 0 && tickets.every(isFreeProduct);
};
