import {useLocation, useParams} from "react-router";
import {ErrorDisplay} from "./components/common/ErrorDisplay";
import {EventImmersiveShell} from "./components/common/EventImmersiveShell";
import {useGetEventPublic} from "./queries/useGetEventPublic.ts";
import {eventHomepagePath} from "./utilites/urlHelper.ts";

const PUBLIC_EVENT_PATHS = /^\/(event|e|checkout|order|venue|product)\//;

const ErrorPage = () => {
    const {eventId} = useParams();
    const {pathname} = useLocation();
    const isPublicEventPage = !!eventId && PUBLIC_EVENT_PATHS.test(pathname);
    const {data: event} = useGetEventPublic(eventId, isPublicEventPage);
    const shellEvent = isPublicEventPage ? event : undefined;

    return (
        <div>
            <EventImmersiveShell event={shellEvent} panel={false}>
                <ErrorDisplay eventUrl={shellEvent ? eventHomepagePath(shellEvent) : undefined}/>
            </EventImmersiveShell>
        </div>
    );
};

export default ErrorPage;
