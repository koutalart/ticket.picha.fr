import {t} from "@lingui/macro";
import {OrganizerSiteContent, OrganizerSiteLabelKey} from "../../../types.ts";

export interface SiteLabelSource {
    name: string;
    site_content?: OrganizerSiteContent | null;
    settings?: { seo_title?: string | null } | null;
}

export const areaSuffix = (organizer: SiteLabelSource) => {
    const area = organizer.site_content?.area;
    return area ? ` ${t`in ${area}`}` : '';
};

export const getSiteLabelDefaults = (organizer: SiteLabelSource): Record<OrganizerSiteLabelKey, string> => {
    const where = areaSuffix(organizer);
    const serviceList = (organizer.site_content?.services || []).slice(0, 3).map(service => service.title.toLowerCase()).join(', ');
    const serviceNames = serviceList.charAt(0).toUpperCase() + serviceList.slice(1);

    return {
        seo_title_home: organizer.settings?.seo_title || t`${organizer.name} | Events and concerts${where}, official ticketing`,
        seo_title_events: t`Events and concerts${where}: line-up and archives | ${organizer.name}`,
        seo_title_services: serviceNames ? t`${serviceNames}${where} | ${organizer.name}` : t`Services${where} | ${organizer.name}`,
        seo_title_about: t`${organizer.name}: story, vision and team${where}`,
        seo_title_partners: t`Partners and sponsorship | ${organizer.name}`,
        seo_title_contact: t`Contact and press | ${organizer.name}`,
        about_button_label: t`Discover our story and vision`,
        partners_subtitle: t`They make it happen.`,
        services_cta_title: t`Let's build your next event.`,
        services_cta_text: t`Tell us about your project: we will get back to you with a tailored proposal.`,
        partner_card_1_text: t`Meet our audience where emotions run high.`,
        partner_card_2_text: t`On site, on our communication materials and on social media.`,
        partner_card_3_text: t`Sponsorship, co-branding, hospitality: let's build it together.`,
        partners_button_label: t`Contact us to become a partner`,
        contact_intro: t`A question about an event, a booking, a project? The ${organizer.name} team answers you.`,
        contact_write_text: t`Send us a message, we will reply by e-mail as soon as possible.`,
        events_intro: t`All upcoming dates by ${organizer.name}, and a look back at past editions.`,
    };
};

export const siteLabel = (organizer: SiteLabelSource, key: OrganizerSiteLabelKey): string => {
    const value = organizer.site_content?.[key];
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : getSiteLabelDefaults(organizer)[key];
};
