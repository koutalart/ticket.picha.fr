import {IdParam} from "../types.ts";
import {CustomerAddressForm, normalizeCustomerAddressForm} from "../locales.ts";
import {useGetOrganizerSettings} from "../queries/useGetOrganizerSettings.ts";

/**
 * The tone (vous / tu) the organizer chose for its customers, for the texts written to them from the dashboard.
 */
export const useOrganizerCustomerAddressForm = (organizerId: IdParam | undefined): {
    addressForm: CustomerAddressForm;
    isFetched: boolean;
} => {
    const query = useGetOrganizerSettings(organizerId as IdParam, {enabled: !!organizerId});

    return {
        addressForm: normalizeCustomerAddressForm(query.data?.customer_address_form),
        isFetched: !organizerId ? false : query.isFetched,
    };
};
