import {Navigate, Outlet} from "react-router";
import {Header} from "../../common/Header";
import {Container} from "@mantine/core";
import {GlobalMenu} from "../../common/GlobalMenu";
import ImpersonationBanner from "../../common/ImpersonationBanner";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {isBoxOfficeOperator} from "../../../utilites/kioskAuth.ts";
import {EVENT_VIEWER_HOME, isEventViewer} from "../../../utilites/eventViewerAuth.ts";

const DefaultLayout = () => {
    const me = useGetMe();

    if (me.isSuccess && isBoxOfficeOperator(me.data)) {
        return <Navigate to="/kiosk" replace/>;
    }

    if (me.isSuccess && isEventViewer(me.data)) {
        return <Navigate to={EVENT_VIEWER_HOME} replace/>;
    }

    return (
        <>
            <ImpersonationBanner />
            <Header rightContent={<GlobalMenu/>}/>
            <Container>
                <Outlet/>
            </Container>
        </>
    );
}

export default DefaultLayout;
