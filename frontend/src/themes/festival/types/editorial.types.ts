/**
 * Types éditoriaux — thème "Festival"
 * ============================================================
 * Données rédigées, vérifiées et maintenues indépendamment de
 * Hi.Events. Aucune donnée ici ne sert à un calcul de prix, de
 * disponibilité ou de paiement — voir hievents.types.ts.
 *
 * Point de jonction unique avec Hi.Events : Festival.hiEventsEventId
 * et FestivalDay.hiEventsProductIds — jamais de duplication de
 * prix/stock ici, uniquement des références d'identifiants.
 */

import { LocalizedText } from './localized.types';
import type { HiEventsEventId, HiEventsProductId } from './hievents.types';

// ----- Artistes -----

export type ArtistConfidenceLevel = 'high' | 'medium_high' | 'medium' | 'low_medium' | 'low';

export type ArtistIdentityStatus =
    | 'verified'
    | 'verified_with_reservations'
    | 'ambiguous_homonym'
    | 'catalogue_matched_no_civil_identity'
    | 'single_combined_act_pending_organizer_confirmation'
    | 'spelling_corrected_from_official_channels'
    | 'matched_to_mahoran_catalogue'
    | 'unverified';

export type OfficialLinkPlatform =
    | 'website' | 'instagram' | 'facebook' | 'tiktok'
    | 'youtube' | 'spotify' | 'apple_music' | 'deezer';

export type LinkVerificationStatus = 'verified_official' | 'likely_official' | 'unverified' | 'explicitly_excluded';

/** Un lien n'est jamais "officiel" du seul fait d'exister — le statut est explicite. */
export interface ArtistOfficialLink {
    platform: OfficialLinkPlatform;
    url: string;
    verificationStatus: LinkVerificationStatus;
}

/** Référence documentaire d'une information éditoriale — traçabilité des recherches. */
export interface SourceReference {
    title: string;
    url: string;
    publisher?: string;
    accessedAt?: string;
}

export interface Artist {
    id: string;
    displayName: string;
    fullName?: string;
    aliases: string[];
    country?: string;
    originCityOrRegion?: string;
    genres: string[];
    shortBiography?: LocalizedText;
    longBiography?: LocalizedText;
    essentialTracks: string[];
    officialLinks: ArtistOfficialLink[];
    imageUrl?: string;
    identityStatus: ArtistIdentityStatus;
    confidence: ArtistConfidenceLevel;
    organizerConfirmationRequired: boolean;
    sources: SourceReference[];
}

// ----- Programmation (jours du festival) -----

export interface DayActivityLocation {
    activity: string;
    locationLabel: string;
}

export type FestivalDayThemeKey = 'opening' | 'discovery' | 'friday' | 'saturday' | 'closing';

export interface FestivalDay {
    id: string;
    /** Date ISO 8601, ex. "2026-08-05" */
    date: string;
    weekdayLabel: LocalizedText;
    conceptTitle: LocalizedText;
    /** Références vers Artist.id — jamais d'objet Artist dupliqué ici. */
    performerIds: string[];
    headlinerIds: string[];
    locationLabel?: string;
    activityLocations?: DayActivityLocation[];
    themeKey: FestivalDayThemeKey;
    /** Heure ISO 8601 complète ou null si inconnue. Jamais de format libre type "20h". */
    doorsOpenAt: string | null;
    startAt: string | null;
    endAt: string | null;
    organizerConfirmationNeeded: string[];
    /** Un jour peut avoir plusieurs billets (VIP, standard, etc.) — références Hi.Events uniquement, jamais de prix ici. */
    hiEventsProductIds?: HiEventsProductId[];
}

// ----- FAQ -----

export interface FaqItem {
    id: string;
    category: string;
    question: LocalizedText;
    answer: LocalizedText;
    publish: boolean;
    verificationStatus: 'confirmed' | 'pending';
}

// ----- Partenaires -----

export interface Partner {
    id: string;
    name: string;
    role?: LocalizedText;
    logoUrl?: string;
    websiteUrl?: string;
    linkTargetApproved: boolean;
    logoUsagePermission: boolean;
}

// ----- Destination -----

export interface Destination {
    name: string;
    country: string;
    description?: LocalizedText;
    highlights: LocalizedText[];
    imageUrl?: string;
}

// ----- Lieu -----

export interface Venue {
    name: string;
    address?: string | null;
    latitude?: number | null;
    longitude?: number | null;
}

// ----- Historique -----

export interface FestivalHistory {
    origin?: LocalizedText;
    firstEditionYear?: number;
}

// ----- Festival (agrégat éditorial racine) -----

export interface Festival {
    slug: string;
    name: string;
    alternateSpelling?: string;
    edition: number;
    tagline?: LocalizedText;
    startDate: string;
    endDate: string;
    heroImageUrl?: string;
    destination: Destination;
    venue: Venue;
    days: FestivalDay[];
    artists: Artist[];
    faq: FaqItem[];
    partners: Partner[];
    publiclyPresentedBy?: string;
    history?: FestivalHistory;
    /** Point de jonction unique avec Hi.Events — jamais de prix/stock dupliqué ici. */
    hiEventsEventId?: HiEventsEventId;
}
