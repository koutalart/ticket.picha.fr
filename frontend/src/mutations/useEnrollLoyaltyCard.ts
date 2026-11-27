import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {EnrollLoyaltyCardRequest, loyaltyClientPublic} from "../api/loyalty.client.ts";

export const useEnrollLoyaltyCard = () => {
    return useMutation({
        mutationFn: ({organizerId, request}: { organizerId: IdParam, request: EnrollLoyaltyCardRequest }) =>
            loyaltyClientPublic.enroll(organizerId, request),
    });
};
