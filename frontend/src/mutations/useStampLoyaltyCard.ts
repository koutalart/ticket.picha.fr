import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {loyaltyClient} from "../api/loyalty.client.ts";

export const useStampLoyaltyCard = () => {
    return useMutation({
        mutationFn: ({organizerId, code}: { organizerId: IdParam, code: string }) =>
            loyaltyClient.stamp(organizerId, code),
    });
};
