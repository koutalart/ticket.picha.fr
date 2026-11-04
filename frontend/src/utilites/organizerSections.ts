import {Organizer, OrganizerSection} from "../types.ts";

const LANDING_ORDER: OrganizerSection[] = ['dashboard', 'events', 'publications', 'sms', 'qr-codes', 'contacts', 'reports'];

const SECTION_BY_SEGMENT: Record<string, OrganizerSection> = {
    '': 'dashboard',
    'dashboard': 'dashboard',
    'events': 'events',
    'organizer-homepage-designer': 'events',
    'contacts': 'contacts',
    'subscribers': 'contacts',
    'reports': 'reports',
    'report': 'reports',
    'qr-codes': 'qr-codes',
    'sms': 'sms',
    'publications': 'publications',
};

export const isSectionEnabled = (organizer: Organizer | undefined, section: OrganizerSection): boolean => {
    const sections = organizer?.enabled_sections;
    return !sections || sections.includes(section);
};

export const firstEnabledSection = (organizer: Organizer | undefined): OrganizerSection | 'settings' => {
    return LANDING_ORDER.find((section) => isSectionEnabled(organizer, section)) ?? 'settings';
};

export const sectionForPath = (pathname: string): OrganizerSection | null => {
    const match = pathname.match(/^\/manage\/organizer\/[^/]+\/?([^/]*)/);
    if (!match) {
        return null;
    }
    return SECTION_BY_SEGMENT[match[1]] ?? null;
};

export const hiddenSectionRedirect = (organizer: Organizer | undefined, pathname: string): string | null => {
    if (!organizer?.id || !organizer.enabled_sections) {
        return null;
    }
    const section = sectionForPath(pathname);
    if (!section || isSectionEnabled(organizer, section)) {
        return null;
    }
    return `/manage/organizer/${organizer.id}/${firstEnabledSection(organizer)}`;
};
