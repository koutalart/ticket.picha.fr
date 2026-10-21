import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";

export const useTestSmsConnection = () => {
    return useMutation({
        mutationFn: ({organizerId}: { organizerId: IdParam }) => smsClient.testConnection(organizerId),
    });
};
