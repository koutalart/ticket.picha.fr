import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {questionClientPublic} from "../api/question.client.ts";

export const GET_EVENT_QUESTIONS_PUBLIC_QUERY_KEY = 'getEventQuestionsPublic';

export const getEventQuestionsPublicQuery = (eventId: IdParam) => ({
    queryKey: [GET_EVENT_QUESTIONS_PUBLIC_QUERY_KEY, String(eventId)],

    queryFn: async () => {
        const {data} = await questionClientPublic.all(eventId);
        return data;
    },

    refetchOnWindowFocus: false,
    staleTime: 60_000,
    retryOnMount: false
});

export const useGetEventQuestionsPublic = (eventId: IdParam) => {
    return useQuery(getEventQuestionsPublicQuery(eventId));
};
