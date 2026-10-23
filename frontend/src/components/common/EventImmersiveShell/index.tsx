import React from "react";
import {Event} from "../../../types.ts";
import {CheckoutThemeProvider} from "../../layouts/Checkout/CheckoutThemeProvider.tsx";
import {ORGANIZER_FONT_ATTRIBUTE} from "../../../utilites/fontLoader.ts";
import {eventHomepagePath} from "../../../utilites/urlHelper.ts";
import {EventImmersiveFooter} from "../EventImmersiveFooter";
import {EventImmersiveContext} from "./EventImmersiveContext.ts";
import {EventImmersiveBackdrop} from "./EventImmersiveBackdrop.tsx";
import {EventImmersiveBrandBar} from "./EventImmersiveBrandBar.tsx";
import {EventImmersiveSignature} from "./EventImmersiveSignature.tsx";
import {useEventImmersive} from "./useEventImmersive.ts";
import classes from "./EventImmersiveShell.module.scss";

export {EventImmersiveBackdrop} from "./EventImmersiveBackdrop.tsx";
export {EventImmersiveBrandBar} from "./EventImmersiveBrandBar.tsx";
export {EventImmersiveSignature} from "./EventImmersiveSignature.tsx";
export {EventImmersiveContext, useIsEventImmersive} from "./EventImmersiveContext.ts";
export {useEventImmersive} from "./useEventImmersive.ts";

interface EventImmersiveShellProps {
    event?: Event;
    children: React.ReactNode;
    panel?: boolean;
    width?: 'narrow' | 'wide';
}

/**
 * Wraps a public page attached to an event. For events using the fullscreen hero, the page is shown
 * over the blurred cover video with the hero logos, the organizer font and the theme colors.
 * Other events are rendered unchanged.
 */
export const EventImmersiveShell = ({event, children, panel = true, width = 'narrow'}: EventImmersiveShellProps) => {
    const immersive = useEventImmersive(event);

    if (!immersive.enabled || !event) {
        return <>{children}</>;
    }

    const {hero} = immersive;

    return (
        <CheckoutThemeProvider accentColor={immersive.accent} mode="dark" fontFamily={immersive.fontStack}>
            <EventImmersiveContext.Provider value={true}>
                <div
                    className={classes.shell}
                    style={immersive.style}
                    {...(immersive.organizerFontActive ? {[ORGANIZER_FONT_ATTRIBUTE]: ''} : {})}
                >
                    <EventImmersiveBackdrop
                        coverVideo={hero.coverVideo}
                        coverVideoMobile={hero.coverVideoMobile}
                        coverImage={hero.coverImage}
                    />
                    <EventImmersiveBrandBar
                        partnerLogo={hero.partnerLogo}
                        brandLogo={hero.brandLogo}
                        organizerLogo={hero.organizerLogo}
                        organizerName={event.organizer?.name}
                        homepageUrl={eventHomepagePath(event)}
                    />
                    <div className={classes.main}>
                        {panel ? (
                            <div className={classes.panel} data-width={width}>
                                {children}
                            </div>
                        ) : children}
                    </div>
                    <EventImmersiveSignature tagline={hero.settings.hero_tagline} signatureLogo={hero.signatureLogo}/>
                    <EventImmersiveFooter event={event}/>
                </div>
            </EventImmersiveContext.Provider>
        </CheckoutThemeProvider>
    );
};

export default EventImmersiveShell;
