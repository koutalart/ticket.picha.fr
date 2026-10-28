import {publicApi} from "./public-client.ts";
import {GenericDataResponse, IdParam, RecentPurchases} from "../types.ts";

export const recentPurchasesClientPublic = {
    get: async (eventId: IdParam) => {
        const response = await publicApi.get<GenericDataResponse<RecentPurchases>>(`events/${eventId}/recent-purchases`);
        return response.data;
    },
};
