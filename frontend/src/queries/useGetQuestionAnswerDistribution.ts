import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {questionClient} from "../api/question.client.ts";

export const GET_QUESTION_ANSWER_DISTRIBUTION_QUERY_KEY = 'getQuestionAnswerDistribution';

export const useGetQuestionAnswerDistribution = (eventId: IdParam) => {
    return useQuery({
        queryKey: [GET_QUESTION_ANSWER_DISTRIBUTION_QUERY_KEY, eventId],
        queryFn: async () => {
            const {data} = await questionClient.answerDistribution(eventId);
            return data;
        },
        enabled: !!eventId,
    });
};
