import React, {useState} from "react";
import {Event} from "../../../types.ts";
import {RichFooter} from "../../layouts/EventHomepage/RichFooter";
import {ContactOrganizerModal} from "../ContactOrganizerModal";
import {socialMediaConfig} from "../../../constants/socialMediaConfig";
import {computeThemeVariables, validateThemeSettings} from "../../../utilites/themeUtils.ts";
import {getFullscreenHero} from "../../../utilites/fullscreenHero.ts";
import {imageUrl} from "../../../utilites/urlHelper.ts";
import {eventRegistrationPath} from "../../../utilites/eventPages.ts";
import {openConsentPreferences} from "../../../utilites/trackingPixels/consent.ts";
import classes from "./EventRichFooter.module.scss";

export const hasEventRichFooter = (event?: Event): boolean =>
    !!event?.organizer
    && !!event.settings?.rich_footer_enabled
    && !!event.settings?.embedded_registration_enabled
    && getFullscreenHero(event).enabled;

export const getOrganizerSocialLinks = (event: Event) => {
    const handles = event.organizer?.settings?.social_media_handles;

    return handles ? Object.entries(handles)
        .filter(([platform, handle]) => handle && socialMediaConfig[platform as keyof typeof socialMediaConfig])
        .map(([platform, handle]) => ({
            platform,
            handle: handle as string,
            config: socialMediaConfig[platform as keyof typeof socialMediaConfig],
        })) : [];
};

/**
 * The rich footer of the event homepage, reused on every other public page of the event.
 */
export const EventRichFooter = ({event}: { event: Event }) => {
    const [contactOpen, setContactOpen] = useState(false);
    const organizer = event.organizer!;

    const themeSettings = validateThemeSettings(event.settings?.homepage_theme_settings);
    const cssVars = computeThemeVariables(themeSettings, organizer.custom_font);
    const style = {
        '--event-primary-color': themeSettings.accent,
        '--event-accent-contrast': cssVars['--theme-accent-contrast'],
        '--theme-font-family': cssVars['--theme-font-family'],
    } as React.CSSProperties;

    return (
        <div className={classes.wrapper} style={style}>
            <RichFooter
                event={event}
                organizer={organizer}
                organizerLogo={imageUrl('ORGANIZER_LOGO', organizer.images)}
                socialLinks={getOrganizerSocialLinks(event)}
                websiteUrl={organizer.website}
                onRegister={() => {
                    window.location.href = eventRegistrationPath(event);
                }}
                onContact={() => setContactOpen(true)}
                onManageCookies={openConsentPreferences}
            />
            <ContactOrganizerModal
                opened={contactOpen}
                onClose={() => setContactOpen(false)}
                organizer={organizer}
                eventId={event.id}
            />
        </div>
    );
};

export default EventRichFooter;
