import {api} from "./client";
import {publicApi} from "./public-client.ts";
import {
    GenericDataResponse,
    IdParam,
    LoyaltyCard,
    LoyaltyProgram,
    LoyaltyProgramPublic,
    LoyaltyProgramSettings,
    LoyaltyStampResult,
} from "../types";

export interface EnrollLoyaltyCardRequest {
    first_name: string;
    email?: string | null;
    phone?: string | null;
}

export const loyaltyClient = {
    getProgram: async (organizerId: IdParam) => {
        const response = await api.get<GenericDataResponse<LoyaltyProgramSettings>>(`organizers/${organizerId}/loyalty-program`);
        return response.data;
    },
    upsertProgram: async (organizerId: IdParam, program: LoyaltyProgram) => {
        const response = await api.put<GenericDataResponse<LoyaltyProgram>>(`organizers/${organizerId}/loyalty-program`, program);
        return response.data;
    },
    stamp: async (organizerId: IdParam, code: string) => {
        const response = await api.post<GenericDataResponse<LoyaltyStampResult>>(`organizers/${organizerId}/loyalty-cards/stamp`, {code});
        return response.data;
    },
};

export const loyaltyClientPublic = {
    getProgram: async (organizerId: IdParam) => {
        const response = await publicApi.get<GenericDataResponse<LoyaltyProgramPublic>>(`organizers/${organizerId}/loyalty-program`);
        return response.data;
    },
    enroll: async (organizerId: IdParam, request: EnrollLoyaltyCardRequest) => {
        const response = await publicApi.post<GenericDataResponse<LoyaltyCard>>(`organizers/${organizerId}/loyalty-cards`, request);
        return response.data;
    },
};
