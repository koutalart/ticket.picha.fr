import {NavLink, Navigate, Outlet, useNavigate, useParams} from "react-router";
import {IconCashRegister, IconLogout, IconSettings, IconSwitchHorizontal} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {useGetBoxOfficeContext} from "../../../queries/useGetBoxOfficeContext.ts";
import {authClient} from "../../../api/auth.client.ts";
import {KioskLogo} from "./KioskLogo";
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
            <aside className={classes.sidebar}>
                <div className={classes.brand}>
                    <KioskLogo className={classes.brandLogo}/>
                </div>
                <nav className={classes.nav} aria-label={t`Kiosk`}>
                    <NavLink
                        to={`/kiosk/event/${eventId}/sell`}
                        className={({isActive}) => `${classes.tab} ${isActive ? classes.tabActive : ''}`}
                    >
                        <IconCashRegister size={22}/>
                        {t`Sales`}
                    </NavLink>
                    <NavLink
                        to={`/kiosk/event/${eventId}/settings`}
                        className={({isActive}) => `${classes.tab} ${isActive ? classes.tabActive : ''}`}
                    >
                        <IconSettings size={22}/>
                        {t`Settings`}
                    </NavLink>
                </nav>
                <div className={classes.sidebarSpacer}/>
                {me.data && (
                    <div className={classes.operatorLine} title={me.data.full_name || me.data.email}>
                        <span className={classes.operatorLabel}>{t`Operator`}</span>
                        <span className={classes.operatorName}>
                            {me.data.first_name || me.data.full_name || me.data.email}
                        </span>
                    </div>
                )}
                <div className={classes.sidebarActions}>
                    {events.length >= 2 && (
                        <button
                            type="button"
                            className={classes.sidebarButton}
                            onClick={() => navigate('/kiosk/select-event')}
                        >
                            <IconSwitchHorizontal size={22}/>
                            {t`Change event`}
                        </button>
                    )}
                    <button type="button" className={classes.sidebarButton} onClick={handleLogout}>
                        <IconLogout size={22}/>
                        {t`Done`}
                    </button>
                </div>
            </aside>
            <div className={classes.main}>
                <div className={classes.content}>
                    <Outlet/>
                </div>
            </div>
        </div>
    );
};

export default KioskLayout;
