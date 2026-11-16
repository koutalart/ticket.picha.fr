import {Link, Navigate} from "react-router";
import {Button, Loader} from "@mantine/core";
import {t} from "@lingui/macro";
import {useGetViewerEvents} from "../../../queries/useGetViewerEvents.ts";
import {prettyDate} from "../../../utilites/dates.ts";
import {LogoutButton} from "./LogoutButton.tsx";
import classes from "./Viewer.module.scss";

const ViewerHome = () => {
    const {data: events, isLoading, isError} = useGetViewerEvents();

    if (isLoading) {
        return <div className={classes.empty}><Loader/></div>;
    }

    if (!isError && events?.length === 1) {
        return <Navigate to={`/lecture/event/${events[0].id}`} replace/>;
    }

    return (
        <div className={classes.page}>
            <div className={classes.header}>
                <h1 className={classes.title}>{t`My events`}</h1>
                <LogoutButton/>
            </div>
            {isError || !events?.length ? (
                <p className={classes.empty}>{t`No event has been assigned to you.`}</p>
            ) : (
                <div className={classes.list}>
                    {events.map(event => (
                        <Button
                            key={event.id}
                            component={Link}
                            to={`/lecture/event/${event.id}`}
                            variant="default"
                            justify="space-between"
                            fullWidth
                        >
                            {event.title}
                            {event.start_date ? `, ${prettyDate(event.start_date, event.timezone || 'UTC')}` : ''}
                        </Button>
                    ))}
                </div>
            )}
        </div>
    );
};

export default ViewerHome;
