import {useMemo} from "react";
import {useParams} from "react-router";
import {useGetBoxOfficeContext} from "../../../../queries/useGetBoxOfficeContext.ts";
import {useGetBoxOfficeProducts} from "../../../../queries/useGetBoxOfficeProducts.ts";
import {KioskPrintOutput, useKioskSettings} from "../../../../hooks/useKioskSettings.ts";
import {SaleForm} from "../../event/BoxOffice/SaleForm";

const KioskSell = () => {
    const {eventId} = useParams();
    const context = useGetBoxOfficeContext();
    const productsQuery = useGetBoxOfficeProducts(eventId);
    const {settings} = useKioskSettings();

    const currentEvent = (context.data?.data ?? []).find((event) => String(event.id) === String(eventId));

    const eligibleProducts = useMemo(() => {
        return (productsQuery.data?.data ?? []).filter((product) =>
            product.is_scannable
            && product.is_available
            && !product.is_hidden
        );
    }, [productsQuery.data]);

    return (
        <SaleForm
            variant="kiosk"
            eventId={eventId}
            eventTitle={currentEvent?.title}
            currency={currentEvent?.currency}
            products={eligibleProducts}
            isLoading={productsQuery.isLoading || !productsQuery.isFetched}
            skipPrint={settings.printOutput === 'none'}
            printMode={settings.printOutput as KioskPrintOutput}
            zebraPrinterHost={settings.zebraPrinterHost}
            defaultLocale={settings.defaultTicketLocale}
            sendConfirmationEmail={settings.sendConfirmationEmail}
        />
    );
};

export default KioskSell;
