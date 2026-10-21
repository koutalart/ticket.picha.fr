import {useQuery} from "@tanstack/react-query";
import {IdParam, OrganizerCustomFont, OrganizerFont} from "../types.ts";
import {organizerClient} from "../api/organizer.client.ts";

export const GET_ORGANIZER_FONTS_QUERY_KEY = 'getOrganizerFonts';

export const useGetOrganizerFonts = (organizerId: IdParam | undefined) => {
    return useQuery<OrganizerFont[]>({
        queryKey: [GET_ORGANIZER_FONTS_QUERY_KEY, organizerId],
        queryFn: async () => {
            const {data} = await organizerClient.getFonts(organizerId as IdParam);
            return data;
        },
        enabled: !!organizerId,
    });
};

export const toOrganizerCustomFont = (fonts: OrganizerFont[] | undefined): OrganizerCustomFont | null => {
    if (!fonts || fonts.length === 0) {
        return null;
    }

    return {
        family_name: fonts[0].family_name,
        files: fonts.map(font => ({weight: font.weight, format: font.format, url: font.url})),
    };
};
