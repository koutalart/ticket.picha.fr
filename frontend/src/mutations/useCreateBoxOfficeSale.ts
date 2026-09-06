import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {boxOfficeClient, CreateBoxOfficeSaleRequest} from "../api/box-office.client.ts";
import {GET_PRODUCTS_QUERY_KEY} from "../queries/useGetProducts.ts";
import {GET_EVENT_QUERY_KEY} from "../queries/useGetEvent.ts";

export const useCreateBoxOfficeSale = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, sale}: {
            eventId: IdParam,
            sale: CreateBoxOfficeSaleRequest,
        }) => boxOfficeClient.createSale(eventId, sale),

        onSuccess: (_, variables) => {
            // Stock (quantity_remaining) just changed.
            return Promise.all([
                queryClient.invalidateQueries({queryKey: [GET_PRODUCTS_QUERY_KEY, variables.eventId]}),
                queryClient.invalidateQueries({queryKey: [GET_EVENT_QUERY_KEY, variables.eventId]}),
            ]);
        },
    });
}
