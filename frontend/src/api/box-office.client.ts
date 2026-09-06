import {api} from "./client";
import {Attendee, GenericDataResponse, IdParam, Order} from "../types";

export enum BoxOfficePaymentMethod {
    Cash = 'CASH',
    Card = 'CARD',
    Free = 'FREE',
}

export interface CreateBoxOfficeSaleItem {
    product_id: number;
    product_price_id: number;
    quantity: number;
}

export interface CreateBoxOfficeSaleRequest {
    product_id?: number;
    product_price_id?: number;
    items?: CreateBoxOfficeSaleItem[];
    phone?: string;
    first_name?: string;
    last_name?: string;
    email?: string;
    locale: string;
    amount: number;
    payment_method: BoxOfficePaymentMethod;
    amount_collected: number;
    idempotency_key: string;
}

export interface BoxOfficeSale {
    sale_id: number | string;
    attendee: Attendee;
    order: Order;
    attendees?: Attendee[];
}

/** GET /box-office/context — events the authenticated user may operate. */
export interface BoxOfficeContextEvent {
    id: number;
    title: string;
    currency: string;
    timezone: string;
}

/** GET /events/{id}/box-office/products — sellable catalogue (scoped). */
export interface BoxOfficeProductPrice {
    id: number;
    label: string | null;
    price: number;
    quantity_remaining: number | null;
}

export interface BoxOfficeProduct {
    id: number;
    title: string;
    product_type: string;
    is_available: boolean;
    is_hidden: boolean;
    is_scannable: boolean;
    prices: BoxOfficeProductPrice[];
}

export type BoxOfficeOperatorStatus = 'ACTIVE' | 'REVOKED';

export interface BoxOfficeOperator {
    user_id: number;
    event_id: number;
    status: BoxOfficeOperatorStatus;
    first_name: string | null;
    last_name: string | null;
    email: string | null;
    created_at: string;
}

export interface CreateBoxOfficeOperatorRequest {
    first_name: string;
    last_name?: string;
    email: string;
}

export const boxOfficeClient = {
    getContext: async () => {
        const response = await api.get<GenericDataResponse<BoxOfficeContextEvent[]>>(
            'box-office/context',
        );
        return response.data;
    },

    getProducts: async (eventId: IdParam) => {
        const response = await api.get<GenericDataResponse<BoxOfficeProduct[]>>(
            `events/${eventId}/box-office/products`,
        );
        return response.data;
    },

    createSale: async (eventId: IdParam, sale: CreateBoxOfficeSaleRequest) => {
        const response = await api.post<GenericDataResponse<BoxOfficeSale>>(
            `events/${eventId}/box-office-sales`, sale,
        );
        return response.data;
    },

    getTicketPdf: async (eventId: IdParam, attendeePublicId: string): Promise<Blob> => {
        const response = await api.get(
            `events/${eventId}/attendees/${attendeePublicId}/ticket.pdf`,
            {responseType: 'blob'},
        );
        return response.data;
    },

    reprintTicket: async (eventId: IdParam, attendeePublicId: string): Promise<Blob> => {
        const response = await api.post(
            `events/${eventId}/attendees/${attendeePublicId}/reprint`, {},
            {responseType: 'blob'},
        );
        return response.data;
    },

    printZpl: async (eventId: IdParam, attendeePublicId: string, printerHost: string) => {
        await api.post(
            `events/${eventId}/attendees/${attendeePublicId}/print-zpl`,
            {printer_host: printerHost},
        );
    },

    getOperators: async (eventId: IdParam) => {
        const response = await api.get<GenericDataResponse<BoxOfficeOperator[]>>(
            `events/${eventId}/box-office/operators`,
        );
        return response.data;
    },

    createOperator: async (eventId: IdParam, operator: CreateBoxOfficeOperatorRequest) => {
        const response = await api.post<GenericDataResponse<BoxOfficeOperator>>(
            `events/${eventId}/box-office/operators`,
            operator,
        );
        return response.data;
    },

    updateOperator: async (eventId: IdParam, userId: IdParam, status: BoxOfficeOperatorStatus) => {
        const response = await api.patch<GenericDataResponse<BoxOfficeOperator>>(
            `events/${eventId}/box-office/operators/${userId}`,
            {status},
        );
        return response.data;
    },
}
