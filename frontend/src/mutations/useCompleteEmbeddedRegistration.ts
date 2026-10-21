import {useMutation} from "@tanstack/react-query";
import {FinaliseOrderPayload, orderClientPublic, ProductFormPayload} from "../api/order.client.ts";
import {IdParam, Order} from "../types.ts";
import {storeSessionIdentifier} from "../utilites/checkoutSession.ts";

export type EmbeddedRegistrationPhase = 'create' | 'complete';

export class EmbeddedRegistrationError extends Error {
    constructor(public readonly phase: EmbeddedRegistrationPhase, public readonly cause: any) {
        super(cause?.message ?? 'Embedded registration failed');
    }
}

export interface PendingEmbeddedOrder {
    shortId: string;
    sessionIdentifier?: string;
    signature: string;
}

interface CompleteEmbeddedRegistrationVariables {
    eventId: IdParam;
    signature: string;
    pendingOrder: PendingEmbeddedOrder | null;
    createPayload: ProductFormPayload;
    completePayload: FinaliseOrderPayload;
    onOrderCreated?: (order: PendingEmbeddedOrder) => void;
}

export const useCompleteEmbeddedRegistration = () => {
    return useMutation<Order, EmbeddedRegistrationError, CompleteEmbeddedRegistrationVariables>({
        mutationFn: async ({eventId, signature, pendingOrder, createPayload, completePayload, onOrderCreated}) => {
            let order = pendingOrder?.signature === signature ? pendingOrder : null;

            if (!order) {
                if (pendingOrder) {
                    orderClientPublic.abandonOrder(eventId, pendingOrder.shortId).catch(() => undefined);
                }
                try {
                    const {data} = await orderClientPublic.create(Number(eventId), createPayload);
                    order = {shortId: String(data.short_id), sessionIdentifier: data.session_identifier, signature};
                } catch (error) {
                    throw new EmbeddedRegistrationError('create', error);
                }
                if (order.sessionIdentifier) {
                    storeSessionIdentifier(order.sessionIdentifier);
                }
                onOrderCreated?.(order);
            }

            try {
                const {data} = await orderClientPublic.finaliseOrder(Number(eventId), order.shortId, completePayload);
                return data;
            } catch (error) {
                throw new EmbeddedRegistrationError('complete', error);
            }
        },
    });
};
