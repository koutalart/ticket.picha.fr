import {LoaderFunctionArgs} from "react-router";
import {publicOrganizerRouteLoader} from "./publicOrganizerRouteLoader.ts";
import {getQueryClient} from "../utilites/ssrQueryClient.ts";
import {getOrganizerPublicEventsQuery} from "../queries/useGetOrganizerEventsPublic.ts";
import {getCustomDomainOrganizer} from "../utilites/customDomain.ts";
import {EventStatus, QueryFilterOperator} from "../types.ts";

export const ORGANIZER_SITE_PAGES = ['a-propos', 'evenements', 'services', 'partenaires', 'contact'] as const;
export type OrganizerSitePage = typeof ORGANIZER_SITE_PAGES[number] | 'home';

const notFound = () => new Response('Not Found', {status: 404});

const loadSitePage = async (
    args: LoaderFunctionArgs,
    organizerId: string,
    organizerSlug: string,
    sitePage: OrganizerSitePage,
    siteBasePath?: string,
    siteOrigin?: string,
) => {
    const data = await publicOrganizerRouteLoader({...args, params: {organizerId, organizerSlug}});

    let pastEventsData = null;
    if (sitePage === 'evenements' && data?.organizer) {
        pastEventsData = await getQueryClient().fetchQuery(getOrganizerPublicEventsQuery(organizerId, {
            pageNumber: 1,
            perPage: 12,
            sortBy: 'start_date',
            sortDirection: 'desc',
            filterFields: {
                end_date: {operator: QueryFilterOperator.LessThanOrEquals, value: 'now'},
                status: {operator: QueryFilterOperator.NotEquals, value: EventStatus.ARCHIVED},
            },
        }));
    }

    return {...data, sitePage, siteBasePath, siteOrigin, pastEventsData};
};

export const customDomainHomeLoader = async (args: LoaderFunctionArgs) => {
    const organizer = getCustomDomainOrganizer(args.request);
    if (!organizer) {
        return null;
    }

    return loadSitePage(args, String(organizer.id), organizer.slug, 'home', '', `https://${organizer.domain}`);
};

export const customDomainSitePageLoader = (sitePage: OrganizerSitePage) => async (args: LoaderFunctionArgs) => {
    const organizer = getCustomDomainOrganizer(args.request);
    if (!organizer) {
        throw notFound();
    }

    return loadSitePage(args, String(organizer.id), organizer.slug, sitePage, '', `https://${organizer.domain}`);
};

export const platformSitePageLoader = async (args: LoaderFunctionArgs) => {
    const {organizerId, organizerSlug, sitePage} = args.params;
    if (!organizerId || !ORGANIZER_SITE_PAGES.includes(sitePage as typeof ORGANIZER_SITE_PAGES[number])) {
        throw notFound();
    }

    return loadSitePage(args, organizerId, organizerSlug || '', sitePage as OrganizerSitePage);
};
