import {getOrganizerTemplate} from "../../organizerTemplates";
import {useLoaderData} from "react-router";
import {Event, GenericPaginatedResponse, Organizer} from "../../../types.ts";
import {OrganizerSitePage} from "../../../routeLoaders/organizerSitePageLoader.ts";
import {OrganizerNotFound} from "./OrganizerNotFound";

export const PublicOrganizer = () => {
    const loaderData = useLoaderData() as {
        organizer: Organizer | null;
        eventsData: any;
        isPastEvents: boolean;
        sitePage?: OrganizerSitePage;
        siteBasePath?: string;
        siteOrigin?: string;
        pastEventsData?: GenericPaginatedResponse<Event> | null;
    };

    if (!loaderData?.organizer) {
        return <OrganizerNotFound />;
    }

    const Template = getOrganizerTemplate(loaderData.organizer.homepage_template);

    return (
        <Template
            organizer={loaderData.organizer}
            eventsData={loaderData.eventsData}
            isPastEvents={loaderData.isPastEvents}
            sitePage={loaderData.sitePage}
            siteBasePath={loaderData.siteBasePath}
            siteOrigin={loaderData.siteOrigin}
            pastEventsData={loaderData.pastEventsData}
        />
    );
};

export default PublicOrganizer;
