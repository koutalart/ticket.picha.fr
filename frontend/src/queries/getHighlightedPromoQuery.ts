import {promoCodeClientPublic} from "../api/promo-code.client.ts";
import {IdParam} from "../types.ts";

export const normalizeHighlightedPromoCode = (code: string | null | undefined) =>
    (code ?? '').trim().toLowerCase() || null;

export const getHighlightedPromoQuery = (eventId: IdParam, code: string | null) => ({
    queryKey: ['embeddedRegistrationHighlightedPromo', String(eventId), code],
    queryFn: () => promoCodeClientPublic.validateCode(eventId, code),
    refetchOnWindowFocus: false,
    retry: 0,
    staleTime: 60_000,
});
