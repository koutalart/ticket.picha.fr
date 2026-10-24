import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {socialClient} from "../api/social.client.ts";

export const useGetMetaLoginUrl = () => {
    return useMutation({
        mutationFn: async ({organizerId}: { organizerId: IdParam }) => (await socialClient.loginUrl(organizerId)).data.url,
    });
};
