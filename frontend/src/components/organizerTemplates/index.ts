import {ComponentType} from "react";
import {t} from "@lingui/macro";
import OrganizerHomepage from "../layouts/OrganizerHomepage";
import PosterTemplate from "./Poster";
import {Event, GenericPaginatedResponse, Organizer} from "../../types.ts";
import type {OrganizerSitePage} from "../../routeLoaders/organizerSitePageLoader.ts";

export interface OrganizerTemplateProps {
    organizer: Organizer;
    eventsData?: GenericPaginatedResponse<Event>;
    isPastEvents?: boolean;
    sitePage?: OrganizerSitePage;
    siteBasePath?: string;
    siteOrigin?: string;
    pastEventsData?: GenericPaginatedResponse<Event> | null;
}

interface OrganizerTemplate {
    label: () => string;
    component: ComponentType<OrganizerTemplateProps>;
}

export const DEFAULT_ORGANIZER_TEMPLATE = 'DEFAULT';

export const ORGANIZER_TEMPLATES: Record<string, OrganizerTemplate> = {
    DEFAULT: {label: () => t`Classic`, component: OrganizerHomepage},
    POSTER: {label: () => t`Poster`, component: PosterTemplate},
};

export const getOrganizerTemplate = (key?: string | null): ComponentType<OrganizerTemplateProps> => {
    return (ORGANIZER_TEMPLATES[key || DEFAULT_ORGANIZER_TEMPLATE] ?? ORGANIZER_TEMPLATES[DEFAULT_ORGANIZER_TEMPLATE]).component;
};
