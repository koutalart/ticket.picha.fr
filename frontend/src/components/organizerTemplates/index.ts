import {ComponentType} from "react";
import {t} from "@lingui/macro";
import OrganizerHomepage from "../layouts/OrganizerHomepage";
import {Event, GenericPaginatedResponse, Organizer} from "../../types.ts";

export interface OrganizerTemplateProps {
    organizer: Organizer;
    eventsData?: GenericPaginatedResponse<Event>;
    isPastEvents?: boolean;
}

interface OrganizerTemplate {
    label: () => string;
    component: ComponentType<OrganizerTemplateProps>;
}

export const DEFAULT_ORGANIZER_TEMPLATE = 'DEFAULT';

export const ORGANIZER_TEMPLATES: Record<string, OrganizerTemplate> = {
    DEFAULT: {label: () => t`Classic`, component: OrganizerHomepage},
};

export const getOrganizerTemplate = (key?: string | null): ComponentType<OrganizerTemplateProps> => {
    return (ORGANIZER_TEMPLATES[key || DEFAULT_ORGANIZER_TEMPLATE] ?? ORGANIZER_TEMPLATES[DEFAULT_ORGANIZER_TEMPLATE]).component;
};
