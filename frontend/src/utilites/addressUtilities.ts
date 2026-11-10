import {VenueAddress} from "../types.ts";

const cleanAddressPart = (part?: string | null) => (part ?? '').replace(/^[\s,]+|[\s,]+$/g, '');

export const joinAddressParts = (parts: (string | null | undefined)[]) => parts
    .map(cleanAddressPart)
    .filter(part => part !== '')
    .join(', ');

export const isAddressSet = (address?: VenueAddress) => {
    if (!address) return false;

    const addressFields: (keyof VenueAddress)[] = [
        'address_line_1',
        'address_line_2',
        'city',
        'state_or_region',
        'zip_or_postal_code',
        'country'
    ];

    return addressFields.some(field => cleanAddressPart(address[field]) !== '')
}

export const formatAddress = (address: VenueAddress) => {
    return joinAddressParts([
        address.address_line_1,
        address.address_line_2,
        address.city,
        address.state_or_region,
        address.zip_or_postal_code,
        address.country
    ]);
}

export const getShortLocationDisplay = (locationDetails?: VenueAddress) => {
    if (!locationDetails) return null;

    return joinAddressParts([locationDetails.venue_name, locationDetails.city]) || null;
};

export const getGoogleMapsUrl = (locationDetails: VenueAddress) => {
    if (!locationDetails) return '';
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(formatAddress(locationDetails))}`;
};
