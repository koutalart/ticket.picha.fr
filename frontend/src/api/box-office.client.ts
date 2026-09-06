import {api} from "./client";
import {Attendee, GenericDataResponse, IdParam, Order} from "../types";

export enum BoxOfficePaymentMethod {
    Cash = 'CASH',
    Card = 'CARD',
    Free = 'FREE',
}

export interface CreateBoxOfficeSaleRequest {
    product_id: number;
    product_price_id: number;
    first_name: string;
    last_name?: string;
    email: string;
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
}

export const boxOfficeClient = {
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
}
