import {Navigate, useNavigate} from "react-router";
import {Button} from "@mantine/core";
import {t} from "@lingui/macro";
import {useGetMe} from "../../../../queries/useGetMe.ts";
import {useGetBoxOfficeContext} from "../../../../queries/useGetBoxOfficeContext.ts";
import {authClient} from "../../../../api/auth.client.ts";
import {TableSkeleton} from "../../../common/TableSkeleton";
import classes from "../../../layouts/Kiosk/Kiosk.module.scss";

const KioskSelectEvent = () => {
    const navigate = useNavigate();
    const me = useGetMe();
    const context = useGetBoxOfficeContext(me.isSuccess);

    if (me.isFetched && me.isError) {
        return <Navigate to="/kiosk/login" replace/>;
    }

    const events = context.data?.data ?? [];

    if (context.isFetched && events.length === 0) {
        return <Navigate to="/kiosk/no-event" replace/>;
    }

    if (context.isFetched && events.length === 1) {
        return <Navigate to={`/kiosk/event/${events[0].id}/sell`} replace/>;
    }

    const handleLogout = async () => {
        try {
            await authClient.logout();
        } finally {
            window.location.href = '/kiosk/login';
        }
    };

    return (
        <div className={classes.page}>
            <TableSkeleton isVisible={!context.isFetched}/>
            {context.isFetched && (
                <>
                    <h1>{t`Choose an event`}</h1>
                    <p>{t`You can sell tickets for these events.`}</p>
                    <div className={classes.eventGrid}>
                        {events.map((event) => (
                            <button
                                key={event.id}
                                type="button"
                                className={classes.eventCard}
                                onClick={() => navigate(`/kiosk/event/${event.id}/sell`)}
                            >
                                <span className={classes.eventCardTitle}>{event.title}</span>
                                <span>{event.currency}</span>
                            </button>
                        ))}
                    </div>
                    <Button variant="subtle" mt="lg" onClick={handleLogout}>
                        {t`Log out`}
                    </Button>
                </>
            )}
        </div>
    );
};

export default KioskSelectEvent;
