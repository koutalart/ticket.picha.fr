import {Navigate} from "react-router";
import {Button} from "@mantine/core";
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
        <div className={classes.page}>
            <h1>{t`No event assigned`}</h1>
            <p>{t`An administrator needs to assign you to an event before you can sell tickets.`}</p>
            <Button variant="subtle" mt="lg" onClick={handleLogout}>
                {t`Log out`}
            </Button>
        </div>
    );
};

export default KioskNoEvent;
