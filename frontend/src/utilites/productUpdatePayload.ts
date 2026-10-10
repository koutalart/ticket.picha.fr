import {Product} from "../types.ts";
import {utcToTz} from "./dates.ts";

/**
 * The product update endpoint replaces the whole product, so quick edits resend every field as the edit form does.
 */
export const productUpdatePayload = (product: Product, timezone: string, overrides: Partial<Product> = {}): Product => ({
    id: product.id,
    title: product.title,
    description: product.description,
    max_per_order: product.max_per_order ?? 0,
    min_per_order: product.min_per_order ?? 0,
    sale_start_date: utcToTz(product.sale_start_date, timezone),
    sale_end_date: utcToTz(product.sale_end_date, timezone),
    hide_before_sale_start_date: product.hide_before_sale_start_date,
    hide_after_sale_end_date: product.hide_after_sale_end_date,
    show_quantity_remaining: product.show_quantity_remaining,
    start_collapsed: product.start_collapsed,
    hide_when_sold_out: product.hide_when_sold_out,
    is_hidden_without_promo_code: product.is_hidden_without_promo_code,
    is_visible_at_box_office: product.is_visible_at_box_office ?? true,
    is_sales_paused: product.is_sales_paused ?? false,
    type: product.type,
    tax_and_fee_ids: product.taxes_and_fees?.map((tax) => String(tax.id)) ?? [],
    is_hidden: product.is_hidden,
    is_highlighted: product.is_highlighted,
    highlight_message: product.highlight_message,
    waitlist_enabled: product.waitlist_enabled ?? null,
    product_type: product.product_type,
    product_category_id: product.product_category_id,
    prices: product.prices?.map((price) => ({
        price: price.price ?? 0,
        label: price.label,
        sale_start_date: utcToTz(price.sale_start_date, timezone),
        sale_end_date: utcToTz(price.sale_end_date, timezone),
        initial_quantity_available: price.initial_quantity_available ?? undefined,
        id: price.id,
        is_hidden: price.is_hidden,
    })) ?? [],
    ...overrides,
} as Product);
