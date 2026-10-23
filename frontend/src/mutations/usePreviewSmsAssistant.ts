import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";

export const usePreviewSmsAssistant = () => {
    return useMutation({
        mutationFn: ({organizerId, message, instructions}: {
            organizerId: IdParam,
            message: string,
            instructions: string | null,
        }) => smsClient.previewAssistant(organizerId, message, instructions),
    });
};
