import {Navigate, useLoaderData} from "react-router";
import {useEffect, useState} from "react";
import {useGetMe} from "../../../queries/useGetMe.ts";
import Landing from "../../routes/landing";
import PublicOrganizer from "../PublicOrganizer";

const PlatformRoot = () => {
    const [redirectPath, setRedirectPath] = useState<string | null>(null);
    const me = useGetMe();

    useEffect(() => {
        if (me.isSuccess) {
            const searchParams = typeof window !== 'undefined' ? window.location.search : '';
            const role = me.data?.role;
            const home = role === 'BOX_OFFICE_OPERATOR' ? "/kiosk" : role === 'EVENT_VIEWER' ? "/lecture" : "/manage/events";
            setRedirectPath(home + searchParams);
        }
    }, [me.isSuccess, me.data?.role]);

    if (redirectPath) {
        return <Navigate to={redirectPath} replace={true}/>;
    }

    return <Landing/>;
};

const RootPage = () => {
    const customDomainData = useLoaderData();

    return customDomainData ? <PublicOrganizer/> : <PlatformRoot/>;
};

export default RootPage;
