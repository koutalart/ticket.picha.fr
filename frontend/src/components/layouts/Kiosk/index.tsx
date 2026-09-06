import {NavLink, Navigate, Outlet, useNavigate, useParams} from "react-router";
import {Button} from "@mantine/core";
import {IconCashRegister, IconLogout, IconSettings, IconSwitchHorizontal} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {useGetBoxOfficeContext} from "../../../queries/useGetBoxOfficeContext.ts";
import {authClient} from "../../../api/auth.client.ts";
import classes from "./Kiosk.module.scss";

const KioskLayout = () => {
    const {eventId} = useParams();
    const navigate = useNavigate();
    const me = useGetMe();
    const context = useGetBoxOfficeContext(me.isSuccess);

    if (me.isFetched && me.isError) {
        return <Navigate to="/kiosk/login" replace/>;
    }

    const events = context.data?.data ?? [];
    const currentEvent = events.find((event) => String(event.id) === String(eventId));

    if (context.isFetched && events.length === 0) {
        return <Navigate to="/kiosk/no-event" replace/>;
    }

    if (context.isFetched && eventId && !currentEvent) {
        return <Navigate to={events.length > 1 ? '/kiosk/select-event' : '/kiosk/no-event'} replace/>;
    }

    const handleLogout = async () => {
        try {
            await authClient.logout();
        } finally {
            window.location.href = '/kiosk/login';
        }
    };

    return (
        <div className={classes.shell}>
            <header className={classes.header}>
                <h1 className={classes.eventTitle}>{currentEvent?.title ?? t`Box Office`}</h1>
                <nav className={classes.tabs} aria-label={t`Kiosk`}>
                    <NavLink
                        to={`/kiosk/event/${eventId}/sell`}
                        className={({isActive}) => `${classes.tab} ${isActive ? classes.tabActive : ''}`}
                    >
                        <IconCashRegister size={18}/>
                        {t`Sell`}
                    </NavLink>
                    <NavLink
                        to={`/kiosk/event/${eventId}/settings`}
                        className={({isActive}) => `${classes.tab} ${isActive ? classes.tabActive : ''}`}
                    >
                        <IconSettings size={18}/>
                        {t`Settings`}
                    </NavLink>
                </nav>
                <div className={classes.actions}>
                    {events.length >= 2 && (
                        <Button
                            variant="light"
                            leftSection={<IconSwitchHorizontal size={16}/>}
                            onClick={() => navigate('/kiosk/select-event')}
                        >
                            {t`Change event`}
                        </Button>
                    )}
                    <Button variant="subtle" leftSection={<IconLogout size={16}/>} onClick={handleLogout}>
                        {t`Log out`}
                    </Button>
                </div>
            </header>
            <div className={classes.content}>
                <Outlet/>
            </div>
        </div>
    );
};

export default KioskLayout;
