import {Navigate} from "react-router";
import {t} from "@lingui/macro";
import {useGetMe} from "../../../../queries/useGetMe.ts";
import {authClient} from "../../../../api/auth.client.ts";
import classes from "../../../layouts/Kiosk/Kiosk.module.scss";

const KioskNoEvent = () => {
    const me = useGetMe();

    if (me.isFetched && me.isError) {
        return <Navigate to="/kiosk/login" replace/>;
    }

    const handleLogout = async () => {
        try {
            await authClient.logout();
        } finally {
            window.location.href = '/kiosk/login';
        }
    };

    return (
        <div className={`${classes.standalonePage} ${classes.page}`}>
            <h1 className={classes.pageTitle}>{t`No event assigned`}</h1>
            <p className={classes.pageLead}>
                {t`An administrator needs to assign you to an event before you can sell tickets.`}
            </p>
            <button type="button" className={classes.textAction} onClick={handleLogout}>
                {t`Done`}
            </button>
        </div>
    );
};

export default KioskNoEvent;
