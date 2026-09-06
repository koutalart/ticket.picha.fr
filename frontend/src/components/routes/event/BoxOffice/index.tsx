import {useMemo} from "react";
import {useParams} from "react-router";
import {useGetEvent} from "../../../../queries/useGetEvent.ts";
import {useGetProducts} from "../../../../queries/useGetProducts.ts";
import {useGetEventCheckInLists} from "../../../../queries/useGetCheckInLists.ts";
import {Product, ProductType} from "../../../../types.ts";
import {SaleForm} from "./SaleForm";

const BoxOffice = () => {
    const {eventId} = useParams();
    const {data: event} = useGetEvent(eventId);
    const {data: productsResponse} = useGetProducts(eventId, {pageNumber: 1, perPage: 100});
    const {data: checkInListsResponse} = useGetEventCheckInLists(eventId);

    const eligibleProducts = useMemo(() => {
        const scannableProductIds = new Set<number>();
        (checkInListsResponse?.data ?? []).forEach((checkInList) => {
            if (checkInList.is_active === false) {
                return;
            }
            checkInList.products.forEach((product) => scannableProductIds.add(product.id));
        });

        return (productsResponse?.data ?? []).filter((product: Product) =>
            product.product_type === ProductType.Ticket
            && product.is_available
            && !product.is_hidden
            && product.id !== undefined
            && scannableProductIds.has(product.id)
        );
    }, [productsResponse, checkInListsResponse]);

    return (
        <SaleForm
            eventId={eventId}
            currency={event?.currency}
            products={eligibleProducts}
            isLoading={!productsResponse || !checkInListsResponse}
        />
    );
};

export default BoxOffice;
