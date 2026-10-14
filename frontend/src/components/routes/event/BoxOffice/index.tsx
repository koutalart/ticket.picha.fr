import {useMemo} from "react";
import {useParams} from "react-router";
import {useGetEvent} from "../../../../queries/useGetEvent.ts";
import {useGetEventSettings} from "../../../../queries/useGetEventSettings.ts";
import {callingCodeFromIso2} from "../../../../utilites/phoneCallingCode.ts";
import {useGetProducts} from "../../../../queries/useGetProducts.ts";
import {useGetBoxOfficeProducts} from "../../../../queries/useGetBoxOfficeProducts.ts";
import {Product, ProductType} from "../../../../types.ts";
import {SaleForm} from "./SaleForm";

const BoxOffice = () => {
    const {eventId} = useParams();
    const {data: event} = useGetEvent(eventId);
    const eventSettingsQuery = useGetEventSettings(eventId);
    const boxOfficeProductsQuery = useGetBoxOfficeProducts(eventId);
    const {data: productsResponse} = useGetProducts(eventId, {pageNumber: 1, perPage: 100});

    const eligibleProducts = useMemo(() => (boxOfficeProductsQuery.data?.data ?? []).filter((product) =>
        product.is_scannable && product.is_available
    ), [boxOfficeProductsQuery.data]);

    const pausedCount = useMemo(() => (productsResponse?.data ?? []).filter((product: Product) =>
        product.product_type === ProductType.Ticket
        && product.is_visible_at_box_office
        && product.is_sales_paused
    ).length, [productsResponse]);

    return (
        <SaleForm
            eventId={eventId}
            eventTitle={event?.title}
            currency={event?.currency}
            phoneCallingCode={callingCodeFromIso2(eventSettingsQuery.data?.location_details?.country)}
            products={eligibleProducts}
            pausedCount={pausedCount}
            isLoading={boxOfficeProductsQuery.isLoading || !boxOfficeProductsQuery.isFetched}
        />
    );
};

export default BoxOffice;
