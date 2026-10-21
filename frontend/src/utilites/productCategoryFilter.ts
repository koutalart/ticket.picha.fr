import {ProductCategory} from "../types.ts";

export const PRODUCT_CATEGORY_QUERY_PARAMS = ['categorie', 'category'];

export const categorySlug = (value: string): string => value
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

/**
 * Categories to display when the page is opened with ?categorie=<name or id>.
 * Matches the category id or its name ("Exposants" = "exposants"). Unknown value: every category.
 */
export const filterProductCategories = <T extends Pick<ProductCategory, 'id' | 'name'>>(
    categories: T[],
    requested?: string | null,
): T[] => {
    const wanted = requested ? categorySlug(requested) : '';
    if (!wanted) {
        return categories;
    }

    const matches = categories.filter(category => String(category.id) === wanted || categorySlug(category.name) === wanted);
    return matches.length > 0 ? matches : categories;
};
