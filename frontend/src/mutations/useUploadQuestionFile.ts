import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {orderClientPublic} from "../api/order.client.ts";

export const useUploadQuestionFile = () => {
    return useMutation({
        mutationFn: ({eventId, orderShortId, questionId, file}: {
            eventId: IdParam,
            orderShortId: IdParam,
            questionId: IdParam,
            file: File,
        }) => orderClientPublic.uploadQuestionFile(eventId, orderShortId, questionId, file),
    });
}
