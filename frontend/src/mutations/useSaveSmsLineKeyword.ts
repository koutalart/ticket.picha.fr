import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {CreateSmsLineKeywordRequest, smsClient, UpdateSmsLineKeywordRequest} from "../api/sms.client.ts";
import {GET_SMS_LINE_KEYWORDS_QUERY_KEY} from "../queries/useGetSmsLineKeywords.ts";

export const useCreateSmsLineKeyword = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, keyword}: { organizerId: IdParam, keyword: CreateSmsLineKeywordRequest }) =>
            smsClient.createLineKeyword(organizerId, keyword),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_SMS_LINE_KEYWORDS_QUERY_KEY, variables.organizerId],
        }),
    });
};

export const useUpdateSmsLineKeyword = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, keywordId, keyword}: {
            organizerId: IdParam,
            keywordId: IdParam,
            keyword: UpdateSmsLineKeywordRequest,
        }) => smsClient.updateLineKeyword(organizerId, keywordId, keyword),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_SMS_LINE_KEYWORDS_QUERY_KEY, variables.organizerId],
        }),
    });
};

export const useDeleteSmsLineKeyword = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, keywordId}: { organizerId: IdParam, keywordId: IdParam }) =>
            smsClient.deleteLineKeyword(organizerId, keywordId),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_SMS_LINE_KEYWORDS_QUERY_KEY, variables.organizerId],
        }),
    });
};
