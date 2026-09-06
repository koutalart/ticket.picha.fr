import {Navigate} from "react-router";
import {useGetMe} from "../../../../queries/useGetMe.ts";
import {useGetBoxOfficeContext} from "../../../../queries/useGetBoxOfficeContext.ts";
import {kioskPathForEvents} from "../../../../utilites/kioskAuth.ts";
import {TableSkeleton} from "../../../common/TableSkeleton";

const KioskHome = () => {
    const me = useGetMe();
    const context = useGetBoxOfficeContext(me.isSuccess);

    if (me.isFetched && me.isError) {
        return <Navigate to="/kiosk/login" replace/>;
    }

    if (!me.isFetched || (me.isSuccess && !context.isFetched)) {
        return <TableSkeleton isVisible/>;
    }

    return <Navigate to={kioskPathForEvents(context.data?.data ?? [])} replace/>;
};

export default KioskHome;
