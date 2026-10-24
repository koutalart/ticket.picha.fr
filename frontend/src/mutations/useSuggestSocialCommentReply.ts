import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {socialClient} from "../api/social.client.ts";

export const useSuggestSocialCommentReply = () => {
    return useMutation({
        mutationFn: async ({organizerId, commentId}: { organizerId: IdParam, commentId: IdParam }) =>
            (await socialClient.suggestReply(organizerId, commentId)).data.message,
    });
};
