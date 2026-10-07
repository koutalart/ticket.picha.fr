import {createRoot} from "react-dom/client";
import {createBrowserRouter, RouterProvider} from "react-router-dom";

import {router} from "./router";
import {App} from "./App";
import {queryClient} from "./utilites/queryClient";
import {dynamicActivateLocale, getClientLocale, getSupportedLocale} from "./locales.ts";
import {restoreNativeAuthToken} from "./native/nativeApp.ts";

const KIOSK_HOME = '/kiosk';

async function initKioskApp() {
    restoreNativeAuthToken();

    const rawLocale = getClientLocale();
    await dynamicActivateLocale(getSupportedLocale(rawLocale));

    if (!window.location.pathname.startsWith(KIOSK_HOME)) {
        window.history.replaceState(null, '', KIOSK_HOME);
    }

    createRoot(document.getElementById("app") as HTMLElement).render(
        <App queryClient={queryClient} locale={rawLocale}>
            <RouterProvider router={createBrowserRouter(router)}/>
        </App>
    );
}

initKioskApp();
