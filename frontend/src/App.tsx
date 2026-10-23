import React, {FC, PropsWithChildren, useEffect, useSyncExternalStore} from "react";
import {MantineProvider} from "@mantine/core";
import {Notifications} from "@mantine/notifications";
import {i18n} from "@lingui/core";
import {I18nProvider} from "@lingui/react";
import {ModalsProvider} from "@mantine/modals";
import {DatesProvider} from "@mantine/dates";
import {HydrationBoundary, QueryClient, QueryClientProvider} from "@tanstack/react-query";
import {Helmet, HelmetProvider} from "react-helmet-async";
import {generatePalette} from './utilites/colorPalette.ts';

import "@mantine/core/styles/global.css";
import "@mantine/core/styles.css";
import "@mantine/notifications/styles.css";
import "@mantine/tiptap/styles.css";
import "@mantine/dropzone/styles.css";
import '@mantine/dates/styles.css';
import "@mantine/charts/styles.css";
import "./styles/global.scss";
import {isSsr} from "./utilites/helpers.ts";
import {StartupChecks} from "./StartupChecks.tsx";
import {ThirdPartyScripts} from "./components/common/ThirdPartyScripts";
import {getConfig} from "./utilites/config.ts";
import {ConsentManager} from "./components/common/ConsentManager";
import {captureAttribution} from "./utilites/attribution";
import {captureQrLinkCodeFromUrl} from "./utilites/qrAttribution";
import {captureContactCodeFromUrl} from "./utilites/contactLinkAttribution";
import "./utilites/dateLocales.ts";
import {getCustomerAddressForm, subscribeToCustomerAddressForm} from "./locales.ts";
import {getAppName} from "./utilites/branding.ts";

declare global {
    interface Window {
        hievents: Record<string, string>;
    }
}

export const App: FC<
    PropsWithChildren<{
        queryClient: QueryClient;
        locale: string;
        helmetContext?: any;
        dehydratedState?: unknown;
    }>
> = (props) => {
    const [isLoadedOnBrowser, setIsLoadedOnBrowser] = React.useState(false);
    const customerAddressForm = useSyncExternalStore(
        subscribeToCustomerAddressForm,
        getCustomerAddressForm,
        getCustomerAddressForm,
    );
    useEffect(() => {
        setIsLoadedOnBrowser(!isSsr());
        captureAttribution();
        captureQrLinkCodeFromUrl();
        captureContactCodeFromUrl();
    }, []);

    return (
        <React.StrictMode>
            <div
                className="ssr-loader"
                style={{
                    top: 0,
                    left: 0,
                    right: 0,
                    bottom: 0,
                    margin: 0,
                    padding: 0,
                    width: "100vw",
                    height: "100vh",
                    position: "fixed",
                    background: "#ffffff",
                    zIndex: 1000,
                    display: isLoadedOnBrowser ? "none" : "block",
                }}
            />
            <MantineProvider
                theme={{
                    colors: {
                        primary: generatePalette(getConfig("VITE_APP_PRIMARY_COLOR", "#40296C") as string),
                        secondary: generatePalette(getConfig("VITE_APP_SECONDARY_COLOR", "#6B4BAF") as string),
                    },
                    primaryColor: "primary",
                    fontFamily: "Outfit, sans-serif",
                    primaryShade: 8,
                }}
            >
                <HelmetProvider context={props.helmetContext}>
                    <I18nProvider i18n={i18n}>
                        <DatesProvider settings={{locale: props.locale}}>
                        <QueryClientProvider client={props.queryClient}>
                            <HydrationBoundary state={props.dehydratedState}>
                                <StartupChecks/>
                                <ThirdPartyScripts/>
                                <ModalsProvider>
                                    <Helmet>
                                        <title>{getAppName()}</title>
                                        <link rel="icon"
                                              type="image/svg+xml"
                                              href={getConfig("VITE_APP_FAVICON", "/favicon.svg")}
                                        />
                                    </Helmet>
                                    <React.Fragment key={customerAddressForm}>
                                        {props.children}
                                    </React.Fragment>
                                </ModalsProvider>
                                <Notifications/>
                                <ConsentManager/>
                            </HydrationBoundary>
                        </QueryClientProvider>
                        </DatesProvider>
                    </I18nProvider>
                </HelmetProvider>
            </MantineProvider>
        </React.StrictMode>
    );
};
