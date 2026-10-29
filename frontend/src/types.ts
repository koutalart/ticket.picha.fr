/**
 * @todo - This file needs to be organized better. Split into multiple files.
 */
import {SupportedLocales} from "./locales.ts";

export type ConfigKeys = 
    | 'VITE_API_URL_SERVER'
    | 'VITE_API_URL_CLIENT'
    | 'VITE_FRONTEND_URL'
    | 'VITE_APP_PRIMARY_COLOR'
    | 'VITE_APP_SECONDARY_COLOR'
    | 'VITE_APP_NAME'
    | 'VITE_APP_FAVICON'
    | 'VITE_APP_LOGO_DARK'
    | 'VITE_APP_LOGO_LIGHT'
    | 'VITE_CHATWOOT_BASE_URL'
    | 'VITE_CHATWOOT_WEBSITE_TOKEN'
    | 'VITE_HIDE_ABOUT_LINK'
    | 'VITE_TOS_URL'
    | 'VITE_TOS_SALE_URL'
    | 'VITE_BRAND_URL'
    | 'VITE_PRIVACY_URL'
    | 'VITE_PLATFORM_SUPPORT_EMAIL'
    | 'VITE_STRIPE_PUBLISHABLE_KEY'
    | 'VITE_I_HAVE_PURCHASED_A_LICENCE'
    | 'VITE_DEFAULT_IMAGE_URL'
    | 'VITE_COOKIE_CONSENT_ENABLED'
    | 'VITE_COOKIE_CONSENT_TEXT'
    | 'VITE_SHOP_URL'
    | 'VITE_LANDING_VIDEO_URL'
    | 'VITE_LANDING_VIDEO_POSTER'
    | 'VITE_FACEBOOK_APP_ID';

export enum StripePlatform {
    Canada = 'ca',
    Ireland = 'ie',
}

export type IdParam = string | undefined | number;

export interface AcceptInvitationRequest {
    first_name: string;
    last_name: string;
    email: string;
    password: string;
    password_confirmation: string;
    marketing_opt_in?: boolean;
}

export interface RegisterAccountRequest extends AcceptInvitationRequest {
    locale: SupportedLocales;
    utm_source?: string | null;
    utm_medium?: string | null;
    utm_campaign?: string | null;
    utm_term?: string | null;
    utm_content?: string | null;
    referrer_url?: string | null;
    landing_page?: string | null;
    gclid?: string | null;
    fbclid?: string | null;
    utm_raw?: Record<string, string> | null;
}

export interface ResetPasswordRequest {
    password: string;
    password_confirmation: string;
}

export interface ColorTheme {
    name: string;
    homepage_background_color: string;
    homepage_content_background_color: string;
    homepage_primary_color: string;
    homepage_primary_text_color: string;
    homepage_secondary_color: string;
    homepage_secondary_text_color: string;
}

export interface HomepageThemeSettings {
    accent: string;
    background: string;
    mode: 'light' | 'dark';
    background_type: 'COLOR' | 'MIRROR_COVER_IMAGE';
    font_family?: string;
    hero_layout?: 'CARD' | 'FULLSCREEN';
    hero_title?: string;
    hero_subtitle?: string;
    hero_tagline?: string;
}

export interface LoginResponse {
    token?: string;
    token_type: string;
    expires_in: number;
    user: User;
    accounts: Account[];
}

export interface User {
    id?: IdParam;
    account_id?: IdParam;
    first_name: string;
    last_name: string;
    full_name: string;
    email: string;
    timezone?: string;
    password?: string;
    is_email_verified?: boolean;
    has_pending_email_change?: boolean;
    is_impersonating?: boolean;
    impersonator_id?: IdParam;
    enforce_email_confirmation_during_registration?: boolean;
    pending_email?: string;
    last_login_at?: string;
    status?: 'ACTIVE' | 'INACTIVE' | 'INVITED';
    role?: 'ADMIN' | 'ORGANIZER' | 'SUPERADMIN' | 'BOX_OFFICE_OPERATOR';
    is_account_owner?: boolean;
    locale?: SupportedLocales;
    marketing_opted_in_at?: string | null;
}

export interface Account {
    id?: IdParam;
    name: string;
    email: string;
    timezone?: string;
    currency_code?: string;
    password?: string;
    stripe_connect_setup_complete?: boolean;
    stripe_account_id?: string;
    is_account_email_confirmed?: boolean;
    is_saas_mode_enabled?: boolean;
    configuration?: AccountConfiguration;
    requires_manual_verification?: boolean;
    stripe_platform: string;
    stripe_hi_events_primary_platform?: string;
}

export interface AccountConfiguration {
    id: IdParam;
    name: string;
    application_fees: {
        percentage: number;
        fixed: number;
        currency: string;
    },
    is_system_default: boolean;
}

export interface StripeConnectDetails {
    account: Account;
    stripe_account_id: string;
    is_connect_setup_complete: boolean;
    connect_url: string | null;
}

export interface StripeConnectAccount {
    stripe_account_id: string;
    connect_url: string | null;
    is_setup_complete: boolean;
    platform: string | null;
    account_type: string | null;
    is_primary: boolean;
    country?: string;
}

export interface StripeConnectAccountsResponse {
    account: {
        id: IdParam;
        stripe_platform: string | null;
    };
    stripe_connect_accounts: StripeConnectAccount[];
    primary_stripe_account_id: string | null;
    has_completed_setup: boolean;
}

export interface LoginData {
    email: string;
    password: string;
}

export interface Image {
    id: IdParam;
    file_name: string;
    url: string;
    size: number;
    mime_type: string;
    type: ImageType;
    width?: number | null;
    height?: number | null;
    avg_colour?: string | null;
    lqip_base64?: string | null;
}

export type ImageType = 'EVENT_COVER' | 'EVENT_COVER_VIDEO' | 'EVENT_COVER_VIDEO_MOBILE' | 'EVENT_FLYER' | 'EVENT_HERO_PARTNER_LOGO' | 'EVENT_HERO_SIGNATURE_LOGO' | 'EVENT_HERO_BRAND_LOGO' | 'EVENT_ARTIST_PHOTO' | 'EVENT_EMAIL_LOGO' | 'EVENT_SHARE_IMAGE' | 'EDITOR_IMAGE' | 'ORGANIZER_LOGO' | 'ORGANIZER_EMAIL_LOGO' | 'ORGANIZER_COVER' | 'ORGANIZER_COVER_VIDEO' | 'ORGANIZER_COVER_VIDEO_MOBILE' | 'ORGANIZER_CHAT_AVATAR' | 'ORGANIZER_IMAGE' | 'QR_POSTER_BACKGROUND' | 'TICKET_LOGO' | 'TICKET_SPONSOR_LOGO';

export type PaymentProvider = 'STRIPE' | 'OFFLINE';

export type AttendeeDetailsCollectionMethod = 'PER_TICKET' | 'PER_ORDER';

export interface EventSettings {
    event_id?: IdParam;
    id?: IdParam;
    continue_button_text: string;
    email_footer_message: string;
    pre_checkout_message: string;
    product_page_message: string;
    post_checkout_message: string;
    support_email?: string;
    notify_organizer_of_new_orders: boolean;
    order_timeout_in_minutes?: number;
    homepage_background_color: string;
    homepage_primary_color: string;
    homepage_primary_text_color: string;
    homepage_secondary_color: string;
    homepage_secondary_text_color: string;
    homepage_body_background_color: string;
    homepage_background_type: 'COLOR' | 'MIRROR_COVER_IMAGE';
    location_details?: VenueAddress;
    is_online_event?: boolean;
    online_event_connection_details?: string;
    maps_url?: string;
    seo_title?: string;
    seo_description?: string;
    seo_keywords?: string;
    allow_search_engine_indexing?: boolean;
    price_display_mode?: 'INCLUSIVE' | 'EXCLUSIVE';
    hide_getting_started_page: boolean;
    attendee_details_collection_method?: AttendeeDetailsCollectionMethod;

    // Payment settings
    offline_payment_instructions: string;
    payment_providers: PaymentProvider[];
    allow_orders_awaiting_offline_payment_to_check_in: boolean;

    // Invoice settings
    enable_invoicing: boolean;
    invoice_label?: string;
    invoice_prefix?: string;
    invoice_start_number?: number;
    require_billing_address: boolean;
    organization_name?: string;
    organization_address?: string;
    invoice_tax_details?: string;
    invoice_notes?: string;
    invoice_payment_terms_days?: number;
    // Ticket design settings
    ticket_design_settings?: {
        accent_color?: string;
        logo_image_id?: IdParam;
        footer_text?: string;
        layout_type?: 'default' | 'modern';
        date_display_mode?: 'START_DATE_TIME' | 'DATE_RANGE' | 'HIDDEN';
        enabled?: boolean;
        sponsor_name?: string;
    };

    // Marketing settings
    show_marketing_opt_in?: boolean;
    highlighted_promo_code?: string | null;

    // Attendee detail copy control
    allow_copy_details_to_all_attendees?: boolean;

    // Platform fee settings
    pass_platform_fee_to_buyer?: boolean;

    // Self-service settings
    allow_attendee_self_edit?: boolean;

    single_product_per_order?: boolean;

    choose_category_first?: boolean;

    // Age restriction settings
    age_restriction_rule?: AgeRestrictionRule;
    age_restriction_minimum_age?: number;

    // Simplified homepage theme settings (new 2-color + mode system)
    homepage_theme_settings?: HomepageThemeSettings;

    // Waitlist settings
    waitlist_auto_process?: boolean;
    waitlist_offer_timeout_minutes?: number | null;

    allow_attendee_attendance_response?: boolean;

    sms_registration_confirmation_enabled?: boolean;
    sms_registration_confirmation_message?: string | null;
    sms_cancellation_confirmation_enabled?: boolean;
    sms_cancellation_confirmation_message?: string | null;
    sms_cancellation_confirmation_on_organizer_cancel?: boolean;

    ticket_footer_use_organizer_domain?: boolean;

    refund_protection_deadline_hours?: number;

    embedded_registration_enabled?: boolean;

    rich_footer_enabled?: boolean;
    homepage_footer_settings?: HomepageFooterSettings | null;
    schema_event_type?: SchemaEventType;
}

export const SCHEMA_EVENT_TYPES = [
    'Event',
    'MusicEvent',
    'TheaterEvent',
    'ComedyEvent',
    'SportsEvent',
    'Festival',
    'BusinessEvent',
    'EducationEvent',
    'SocialEvent',
] as const;

export type SchemaEventType = typeof SCHEMA_EVENT_TYPES[number];

export interface HomepageFooterEventLink {
    label: string;
    url: string;
}

export interface HomepageFooterRelatedLink {
    title: string;
    url: string;
    image_url?: string | null;
}

export type NewsletterSubscriberSource = 'FOOTER' | 'REGISTRATION';

export interface NewsletterSubscriber {
    id: IdParam;
    email: string;
    source: NewsletterSubscriberSource;
    event_id?: IdParam | null;
    event_title?: string | null;
    consent_text: string;
    consented_at: string;
}

export interface HomepageFooterSettings {
    newsletter_enabled?: boolean;
    newsletter_title?: string | null;
    privacy_policy_url?: string | null;
    terms_url?: string | null;
    accessibility_url?: string | null;
    event_links?: HomepageFooterEventLink[];
    related_links?: HomepageFooterRelatedLink[];
}

export interface VenueAddress {
    venue_name?: string;
    address_line_1?: string;
    address_line_2?: string;
    city?: string;
    state_or_region?: string;
    zip_or_postal_code?: string;
    country?: string;
}

export interface EventBase {
    title: string;
    description?: string;
    category?: string;
    start_date: string;
    end_date?: string;
}

export interface EventDuplicatePayload extends EventBase {
    duplicate_products: boolean;
    duplicate_questions: boolean;
    duplicate_settings: boolean;
    duplicate_promo_codes: boolean;
    duplicate_capacity_assignments: boolean;
    duplicate_check_in_lists: boolean;
    duplicate_event_cover_image: boolean;
    duplicate_ticket_logo: boolean;
    duplicate_webhooks: boolean;
    duplicate_affiliates: boolean;
}

export enum EventStatus {
    DRAFT = 'DRAFT',
    LIVE = 'LIVE',
    PAUSED = 'PAUSED',
    ARCHIVED = 'ARCHIVED'
}

export enum OrganizerStatus {
    DRAFT = 'DRAFT',
    LIVE = 'LIVE',
    ARCHIVED = 'ARCHIVED'
}

export enum EventLifecycleStatus {
    ONGOING = 'ONGOING',
    UPCOMING = 'UPCOMING',
    ENDED = 'ENDED'
}

export interface Event extends EventBase {
    id?: IdParam;
    slug: string;
    status?: EventStatus;
    description_preview?: string;
    lifecycle_status?: EventLifecycleStatus;
    settings?: EventSettings;
    products?: Product[];
    product_categories?: ProductCategory[];
    images?: Image[];
    organizer?: Organizer;
    currency: string;
    timezone: string;
    organizer_id?: IdParam;
    location_details?: VenueAddress;
    statistics?: EventStatistics;
    attributes?: { name: string; value: string; is_public?: boolean }[];
    dedicated_pages?: EventPageSlug[];
}

export type EventPageSlug = 'artistes' | 'infos-pratiques' | 'faq';

export type EventArtistLinkType = 'instagram' | 'facebook' | 'tiktok' | 'youtube' | 'spotify' | 'website';

export interface EventArtist {
    name: string;
    slug?: string | null;
    kind?: 'PERSON' | 'GROUP' | null;
    image_id?: IdParam | null;
    photo_url?: string | null;
    bio?: string | null;
    song?: string | null;
    links?: Partial<Record<EventArtistLinkType, string>>;
}

export interface EventPageSection {
    title: string;
    content?: string | null;
}

export interface EventFaqItem {
    question: string;
    answer: string;
}

export interface EventPages {
    available_pages?: EventPageSlug[];
    artists: EventArtist[];
    practical_info: EventPageSection[];
    faq: EventFaqItem[];
}

export interface EventStatistics {
    unique_views: number;
    total_views: number;
    sales_total_gross: number;
    total_tax: number;
    sales_total_before_additions: number;
    total_fee: number;
    products_sold: number;
    attendees_registered: number;
    total_refunded: number;
}

export interface EventDailyStats {
    date: string;
    total_fees: number;
    total_tax: number;
    total_sales_gross: number;
    products_sold: number;
    attendees_registered: number;
    total_refunded: number;
    orders_created: number;
}

export interface QuestionAnswerDistributionOption {
    label: string;
    count: number;
    percentage: number;
}

export interface QuestionAnswerDistribution {
    question_id: number;
    title: string;
    type: string;
    belongs_to: 'ORDER' | 'PRODUCT';
    total_responses: number;
    options: QuestionAnswerDistributionOption[];
}

export interface CheckInStats {
    total_checked_in_attendees: number;
    total_attendees: number;
}

export interface EventStats {
    daily_stats: EventDailyStats[];
    start_date: string;
    end_date: string;
    check_in_stats: CheckInStats;
    total_products_sold: number;
    total_attendees_registered: number;
    total_product_sold_percentage_change: number;
    total_orders: number;
    total_orders_percentage_change: number;
    total_gross_sales: number;
    total_gross_sales_percentage_change: number;
    total_tax: number;
    total_fees: number;
    total_views: number;
    total_refunded: number;
    total_awaiting_payment?: number;
    orders_awaiting_payment?: number;
    total_attendance_confirmed?: number;
    total_cancelled_by_attendee?: number;
}

export interface OrganizerStats {
    total_products_sold: number;
    total_attendees_registered: number;
    total_orders: number;
    total_gross_sales: number;
    total_tax: number;
    total_fees: number;
    total_views: number;
    total_refunded: number;
    all_organizers_currencies: string[];
    total_awaiting_payment?: number;
    orders_awaiting_payment?: number;
}

export interface OrganizerSiteContent {
    tagline?: string | null;
    area?: string | null;
    about_headline?: string | null;
    story?: string | null;
    vision?: string | null;
    about_image_url?: string | null;
    values?: { title: string; text?: string | null }[] | null;
    stats?: { value: string; label: string }[] | null;
    team?: { name: string; role?: string | null; photo_url?: string | null }[] | null;
    gallery?: { url: string; caption?: string | null }[] | null;
    services_intro?: string | null;
    services?: { title: string; text?: string | null }[] | null;
    partners_intro?: string | null;
    partners?: { name: string; logo_url?: string | null; url?: string | null; category?: string | null }[] | null;
    press_text?: string | null;
    press_email?: string | null;
    press_phone?: string | null;
    press_kit_url?: string | null;
    intro_title?: string | null;
    intro_text?: string | null;
    upcoming_note?: string | null;
    notify_url?: string | null;
    videos?: { youtube_id: string; title: string; subtitle?: string | null }[] | null;
    practical?: { title: string; text?: string | null }[] | null;
    seo_title_home?: string | null;
    seo_title_services?: string | null;
    seo_title_about?: string | null;
    seo_title_partners?: string | null;
    seo_title_events?: string | null;
    seo_title_contact?: string | null;
    about_button_label?: string | null;
    partners_subtitle?: string | null;
    services_cta_title?: string | null;
    services_cta_text?: string | null;
    partner_card_1_text?: string | null;
    partner_card_2_text?: string | null;
    partner_card_3_text?: string | null;
    partners_button_label?: string | null;
    contact_intro?: string | null;
    contact_write_text?: string | null;
    events_intro?: string | null;
}

export const ORGANIZER_SITE_LABEL_KEYS = [
    'seo_title_home',
    'seo_title_services',
    'seo_title_about',
    'seo_title_partners',
    'seo_title_events',
    'seo_title_contact',
    'about_button_label',
    'partners_subtitle',
    'services_cta_title',
    'services_cta_text',
    'partner_card_1_text',
    'partner_card_2_text',
    'partner_card_3_text',
    'partners_button_label',
    'contact_intro',
    'contact_write_text',
    'events_intro',
] as const;

export type OrganizerSiteLabelKey = typeof ORGANIZER_SITE_LABEL_KEYS[number];

export interface Organizer {
    id?: IdParam;
    name: string;
    email: string;
    description?: string;
    website?: string;
    timezone?: string;
    currency?: string;
    slug?: string;
    phone?: string;
    homepage_template?: string;
    site_content?: OrganizerSiteContent | null;
    custom_domain?: string | null;
    images?: Image[];
    events?: Event[];
    settings?: OrganizerSettings;
    location_details?: VenueAddress;
    status?: OrganizerStatus;
    custom_font?: OrganizerCustomFont | null;
}

export type OrganizerFontFormat = 'woff2' | 'woff' | 'truetype' | 'opentype';

export interface OrganizerCustomFontFile {
    weight: number;
    format: OrganizerFontFormat;
    url: string;
}

export interface OrganizerCustomFont {
    family_name: string;
    files: OrganizerCustomFontFile[];
}

export interface OrganizerFont extends OrganizerCustomFontFile {
    id: IdParam;
    organizer_id: IdParam;
    family_name: string;
    file_name: string;
    size: number;
    updated_at?: string;
}

export interface OrganizerSettings {
    id: IdParam;
    organizer_id: IdParam;
    default_attendee_details_collection_method?: AttendeeDetailsCollectionMethod;
    default_show_marketing_opt_in?: boolean;
    default_pass_platform_fee_to_buyer?: boolean;
    default_allow_attendee_self_edit?: boolean;
    homepage_visibility: 'PUBLIC' | 'PRIVATE' | 'PASSWORD_PROTECTED';
    homepage_theme_settings: HomepageThemeSettings;
    website_url?: string;
    location_details?: VenueAddress;
    social_media_handles?: {
        facebook?: string;
        instagram?: string;
        twitter?: string;
        linkedin?: string;
        youtube?: string;
        tiktok?: string;
        snapchat?: string;
        twitch?: string;
        discord?: string;
        github?: string;
        reddit?: string;
        pinterest?: string;
        whatsapp?: string;
        telegram?: string;
        wechat?: string;
        weibo?: string;
    },
    seo_keywords?: string;
    seo_description?: string;
    seo_title?: string;
    allow_search_engine_indexing?: boolean;
    tracking_pixels?: TrackingPixelConfig[];
    tracking_consent_acknowledged?: boolean;
    meta_conversions_api_configured?: boolean;
    meta_test_event_code?: string | null;
    meta_conversions_api_token?: string | null;
    meta_capi_send_without_consent?: boolean;
    email_sender_name?: string | null;
    customer_address_form?: CustomerAddressForm;
}

export type CustomerAddressForm = 'vous' | 'tu';

export interface TrackingPixelConfig {
    provider: string;
    pixel_id: string;
    enabled: boolean;
}

export type QrLinkDestinationType = 'LINK_PAGE' | 'EVENT' | 'URL' | 'APP';

export type QrLinkStatus = 'ACTIVE' | 'PAUSED';

export type QrLinkSocialPlatform = 'instagram' | 'facebook' | 'tiktok' | 'whatsapp' | 'snapchat' | 'youtube' | 'x' | 'linkedin' | 'telegram' | 'threads' | 'website';

export interface QrLinkSocialLink {
    platform: QrLinkSocialPlatform;
    url: string;
}

export type QrPosterTemplate = 'none' | 'story' | 'poster' | 'square' | 'custom';

export type QrPosterLogo = 'organizer' | 'website' | 'video' | 'podcast' | 'app' | 'instagram' | 'facebook' | 'tiktok' | 'whatsapp' | 'youtube' | 'x' | 'threads' | 'snapchat' | 'linkedin' | 'telegram';

export interface QrLinkDesign {
    foreground?: string;
    background?: string;
    template?: QrPosterTemplate;
    background_image_id?: IdParam | null;
    background_image_url?: string;
    background_width?: number;
    background_height?: number;
    qr_position?: number;
    qr_scale?: number;
    caption?: string;
    caption_color?: string;
    poster_title?: string;
    poster_logo?: QrPosterLogo;
}

export interface QrLink {
    id: IdParam;
    short_id: string;
    public_url: string;
    name: string;
    destination_type: QrLinkDestinationType;
    destination_url: string | null;
    app_store_url: string | null;
    play_store_url: string | null;
    event_id: IdParam | null;
    event_title: string | null;
    qr_campaign_id: IdParam | null;
    title: string | null;
    description: string | null;
    social_links: QrLinkSocialLink[];
    design: QrLinkDesign;
    status: QrLinkStatus;
    scans_count: number;
    unique_scans_count: number;
    clicks_count: number;
    orders_count: number;
    revenue_gross: number;
    created_at: string;
    updated_at: string;
}

export interface QrCampaign {
    id: IdParam;
    name: string;
    created_at: string;
}

export interface QrLinkPublic {
    short_id: string;
    name: string;
    organizer_id: IdParam;
    event_id: IdParam | null;
    event_title: string | null;
    ticket_url: string | null;
    destination_type: QrLinkDestinationType;
    title: string | null;
    description: string | null;
    social_links: QrLinkSocialLink[];
    design: QrLinkDesign;
}

export interface QrLinkBreakdownItem {
    key: string | null;
    count: number;
}

export interface QrLinkStats {
    qr_link_id: IdParam;
    days: number | null;
    timezone: string;
    scans: number;
    unique_scans: number;
    clicks: number;
    orders: number;
    revenue_gross: number;
    conversion_rate: number | null;
    daily: { date: string; scans: number; unique_scans: number; clicks: number }[];
    hours: { hour: number; count: number }[];
    devices: QrLinkBreakdownItem[];
    operating_systems: QrLinkBreakdownItem[];
    browsers: QrLinkBreakdownItem[];
    countries: QrLinkBreakdownItem[];
    referrers: QrLinkBreakdownItem[];
    clicks_by_target: QrLinkBreakdownItem[];
}

export interface MetaConversionsApiTestResult {
    pixel_id: string;
    pixel_name: string | null;
    test_event_sent: boolean;
}

export interface SortDirectionLabel {
    asc: string;
    desc: string;
}

export interface PaginationData {
    total: number;
    per_page: number;
    current_page: number;
    links: string[];
    last_page: number;
    from: number;
    to: number;
    path: number;
    allowed_sorts: Record<string, SortDirectionLabel>;
    default_sort: string;
    default_sort_direction: string;
}

export interface GenericDataResponse<T> {
    data: T;
    errors?: Record<string, string>;
}

export interface GenericPaginatedResponse<T> {
    data: T[];
    meta: PaginationData;
}

export enum ProductPriceType {
    Paid = 'PAID',
    Donation = 'DONATION',
    Free = 'FREE',
    Tiered = 'TIERED',
}

export enum ProductType {
    Ticket = 'TICKET',
    General = 'GENERAL',
}

export enum ProductStatus {
    Active = 'ACTIVE',
    Inactive = 'INACTIVE',
}

export interface ProductPrice {
    id?: number;
    label?: string;
    price: number;
    sale_start_date?: string | Date;
    sale_end_date?: string | Date;
    price_including_taxes_and_fees?: number;
    price_before_discount?: number;
    is_discounted?: boolean;
    tax_total?: number;
    fee_total?: number;
    is_available?: boolean;
    is_before_sale_start_date?: boolean;
    is_after_sale_end_date?: boolean;
    is_sold_out?: boolean;
    initial_quantity_available?: number;
    quantity_sold?: number;
    is_hidden?: boolean;
    quantity_remaining?: number;
    refund_protection_included?: number | null;
    low_stock?: ProductPriceLowStock | null;
}

export interface ProductPriceLowStock {
    remaining: number;
    percent_remaining: number;
}

export interface RecentPurchase {
    quantity: number;
    minutes_ago: number;
}

export interface RecentPurchases {
    purchases: RecentPurchase[];
}

export type RequiredQuantityMode = 'PER_ORDER' | 'PER_TICKET';

export interface Product {
    id?: number;
    order?: number;
    title: string;
    event_id?: IdParam;
    // todo - rename to price_type
    type: ProductPriceType;
    product_type: ProductType;
    description?: string;
    price?: number;
    prices?: ProductPrice[];
    price_before_discount?: number;
    is_discounted?: boolean;
    initial_quantity_available?: number | undefined;
    quantity_sold?: number;
    sale_start_date?: string | Date;
    sale_end_date?: string | Date;
    max_per_order?: number;
    min_per_order?: number;
    hide_before_sale_start_date?: boolean;
    hide_after_sale_end_date?: boolean;
    hide_when_sold_out?: boolean;
    start_collapsed?: boolean;
    show_quantity_remaining?: boolean;
    quantity_available?: number;
    status?: ProductStatus;
    is_sold_out?: boolean;
    is_available?: boolean;
    is_hidden_without_promo_code?: boolean;
    is_visible_at_box_office?: boolean;
    is_sales_paused?: boolean;
    is_required?: boolean;
    required_quantity_mode?: RequiredQuantityMode;
    is_refund_protection?: boolean;
    is_before_sale_start_date?: boolean;
    is_after_sale_end_date?: boolean;
    taxes?: TaxAndFee[];
    tax_total?: number;
    service_fee_total?: number;
    price_including_taxes_and_fees?: number;
    tax_and_fee_ids?: IdParam[];
    taxes_and_fees?: TaxAndFee[];
    is_hidden?: boolean;
    product_category_id?: IdParam;
    is_highlighted?: boolean;
    highlight_message?: string;
    waitlist_enabled?: boolean | null;
    unlock_after_product_id?: number | null;
    has_waiting_entries?: boolean;
    waitlist_entry_count?: number;
}

export interface ProductCategory {
    id?: number;
    name: string;
    description?: string;
    products?: Product[];
    event_id?: number;
    is_hidden?: boolean;
    no_products_message?: string;
    selection_label?: string | null;
}

export type AgeRestrictionRule = 'NONE' | 'ACCOMPANIED_MINORS' | 'MINIMUM_AGE';

export interface Attendee {
    id?: number;
    product_id: number;
    product?: Product;
    product_price_id: number;
    order_id: number;
    status: 'ACTIVE' | 'CANCELLED' | 'AWAITING_PAYMENT';
    first_name: string;
    last_name: string;
    email: string | null;
    notes?: string;
    birth_date?: string | null;
    order?: Order;
    public_id: string;
    short_id: string;
    checked_in_at?: string;
    checked_out_by?: number;
    checked_in_by?: number;
    question_answers?: QuestionAnswer[];
    locale?: SupportedLocales;
    check_in?: AttendeeCheckIn; // Use in contexts where a single check is expected, like dealing with a check-in list
    check_ins?: AttendeeCheckIn[];
    attendance_confirmed_at?: string | null;
    cancelled_by_attendee_at?: string | null;
    attendance_code?: string;
}

export interface AttendanceAccessLink {
    short_id: string;
    first_name: string;
    code: string;
}

export interface AttendeeAttendance {
    short_id: string;
    first_name: string;
    last_name: string;
    status: Attendee['status'];
    product_title?: string | null;
    attendance_confirmed_at: string | null;
    cancelled_by_attendee_at: string | null;
    can_respond: boolean;
    blocking_reason: string | null;
}

export type PublicCheckIn = Pick<AttendeeCheckIn, 'id' | 'order_id' | 'attendee_id' | 'check_in_list_id' | 'product_id' | 'event_id'> & {
    short_id?: string;
    attendee?: Pick<Attendee, 'public_id' | 'first_name' | 'last_name'>;
};

export interface CheckInListLiveStats {
    checked_in: number;
    paper_checked_in: number;
    outside: number;
    paper_not_scanned: number;
    expected: number;
}

export interface AttendeeCheckIn {
    id: IdParam;
    attendee_id: IdParam;
    check_in_list_id: IdParam;
    product_id: IdParam;
    event_id: IdParam;
    short_id: IdParam;
    order_id: IdParam;
    created_at: string;
    check_in_list?: CheckInList;
}

export interface Address {
    address_line_1: string;
    address_line_2: string;
    city: string;
    state_or_region: string;
    country: string;
    zip_or_postal_code: string;
}

interface TaxOrFee {
    name: string;
    value: number;
}

interface TaxesAndFeesRollup {
    fees: TaxOrFee[];
    taxes: TaxOrFee[];
}

export interface Order {
    id: IdParam;
    short_id: string;
    event_id: IdParam;
    first_name: string;
    last_name: string;
    company_name: string;
    address: Address;
    payment_provider: PaymentProvider;
    notes?: string;
    email: string;
    reserved_until: string;
    total_before_additions: number;
    total_tax: number;
    total_fee: number;
    total_gross: number;
    total_gross_after_refund: number;
    total_refunded: number;
    is_expired: boolean;
    order_items?: OrderItem[];
    attendees?: Attendee[];
    created_at: string;
    currency: string;
    status: 'RESERVED' | 'CANCELLED' | 'COMPLETED' | 'AWAITING_OFFLINE_PAYMENT' | 'ABANDONED';
    refund_status?: 'REFUND_PENDING' | 'REFUND_FAILED' | 'REFUNDED' | 'PARTIALLY_REFUNDED';
    payment_status?: 'NO_PAYMENT_REQUIRED' | 'AWAITING_PAYMENT' | 'PAYMENT_FAILED' | 'PAYMENT_RECEIVED' | 'AWAITING_OFFLINE_PAYMENT';
    public_id: string;
    is_payment_required: boolean;
    is_manually_created: boolean;
    is_free_order: boolean;
    promo_code?: string;
    promo_code_id?: number;
    taxes_and_fees_rollup?: TaxesAndFeesRollup;
    question_answers?: QuestionAnswer[];
    event?: Event;
    latest_invoice?: Invoice;
    session_identifier?: string;
}

export interface Invoice {
    download_url: string;
    invoice_number: string;
    id: IdParam,
    order_id: IdParam,
    status: 'PAID' | 'UNPAID' | 'VOID',
}

export interface OrderItem {
    id: number;
    product_id: number;
    product_price_id: number;
    item_name: string;
    total_before_additions: number;
    total_before_discount?: number;
    price_before_discount?: number;
    price: number;
    quantity: number;
    total_gross?: number;
}

export interface RefundProtectionOffer {
    product_id: number;
    title: string;
    description: string | null;
    quantity: number;
    is_per_ticket: boolean;
    total_before_additions: number;
    total_tax: number;
    total_fee: number;
    total_gross: number;
    currency: string;
    is_selected: boolean;
    is_locked: boolean;
    mandatory_quantity?: number;
    mandatory_total_gross?: number;
    mandatory_above?: number | null;
    is_required_by_organizer?: boolean;
}

export interface StripePaymentIntent {
    status: string;
    paymentIntentId: string;
    amount: number;
}

export interface Question {
    id?: number;
    title: string;
    description?: string;
    required: boolean;
    type: string;
    options: string[];
    event_id?: number;
    products?: Product[];
    product_ids?: number[];
    belongs_to: string;
    is_hidden: boolean;
    order?: number;
}

export interface CapacityAssignment {
    id?: number;
    event_id: number;
    name: string;
    used_capacity: number;
    status: 'ACTIVE' | 'INACTIVE';
    capacity: number | undefined;
    products: {
        id: number;
        title: string;
    }[];
}

export type CapacityAssignmentRequest = Omit<CapacityAssignment, 'id' | 'event_id' | 'used_capacity' | 'products'> & {
    product_ids: IdParam[];
};

export interface CheckInList {
    id?: number;
    short_id: string;
    name: string;
    description?: string | null;
    expires_at?: string;  // ISO 8601 string
    activates_at?: string;  // ISO 8601 string
    total_attendees: number;
    checked_in_attendees: number;
    is_expired: boolean;
    is_active: boolean;
    event_id: number;
    event?: Event;
    products: {
        id: number;
        title: string;
    }[];
}

export type CheckInListRequest =
    Omit<CheckInList, 'event_id' | 'short_id' | 'id' | 'products' | 'total_attendees' | 'checked_in_attendees' | 'is_expired' | 'is_active'>
    & {
    product_ids: IdParam[];
};

export interface QuestionRequestData {
    title: string;
    description?: string;
    required: boolean;
    is_hidden: boolean;
    type: string;
    options: string[];
    product_ids?: string[];
    belongs_to: string;
}

export interface Message {
    id?: IdParam;
    subject: string;
    message: string;
    message_preview: string;
    type: MessageType;
    is_test: boolean;
    order_id?: number;
    attendee_ids?: IdParam[];
    product_ids?: IdParam[];
    created_at?: string;
    updated_at?: string;
    sent_at?: string;
    scheduled_at?: string | null;
    sent_by_user?: User;
    status?: 'SENT' | 'PROCESSING' | 'FAILED' | 'SCHEDULED' | 'CANCELLED' | 'PENDING_REVIEW';
}

export interface OutgoingMessage {
    id?: IdParam;
    message_id: number;
    recipient: string;
    status: string;
    subject: string;
    created_at?: string;
}

export enum QuestionType {
    ADDRESS = 'ADDRESS',
    PHONE = 'PHONE',
    SINGLE_LINE_TEXT = 'SINGLE_LINE_TEXT',
    MULTI_LINE_TEXT = 'MULTI_LINE_TEXT',
    CHECKBOX = 'CHECKBOX',
    RADIO = 'RADIO',
    DROPDOWN = 'DROPDOWN',
    DATE = 'DATE',
    FILE = 'FILE',
}

export enum QuestionBelongsToType {
    PRODUCT = 'PRODUCT',
    ORDER = 'ORDER',
}

export enum QueryFilterOperator {
    Equals = 'eq',
    NotEquals = 'ne',
    GreaterThan = 'gt',
    GreaterThanOrEquals = 'gte',
    LessThan = 'lt',
    LessThanOrEquals = 'lte',
    Like = 'like',
    NotLike = 'not_like',
    In = 'in',
}

export type QueryFilterValue = string | number | boolean;

export type QueryFilterCondition = {
    operator: QueryFilterOperator;
    value: QueryFilterValue;
};

export type QueryFilterFields = {
    [key: string]: QueryFilterCondition | QueryFilterCondition[] | undefined;
}

export interface QueryFilters {
    pageNumber?: number;
    perPage?: number;
    query?: string;
    sortBy?: string;
    sortDirection?: string;
    filterFields?: QueryFilterFields;
    additionalParams?: Record<string, any>;
}

export interface GenericModalProps {
    onClose: () => void;
    isOpen?: boolean;
}

export interface MessageOrderRequest {
    subject: string;
    message: string;
    sendCopy: boolean;
}

export enum MessageType {
    IndividualAttendees = 'INDIVIDUAL_ATTENDEES',
    OrderOwner = 'ORDER_OWNER',
    TicketHolders = 'TICKET_HOLDERS',
    AllAttendees = 'ALL_ATTENDEES',
    OrderOwnersWithProduct = 'ORDER_OWNERS_WITH_PRODUCT',
}

export interface PromoCode {
    id?: number;
    code: string;
    discount?: number;
    applicable_product_ids?: number[] | string[];
    expiry_date?: string;
    event_id?: number;
    discount_type?: PromoCodeDiscountType | null;
    attendee_usage_count?: number;
    order_usage_count?: number;
    max_allowed_usages?: number | undefined;
}

export enum PromoCodeDiscountType {
    Percentage = 'PERCENTAGE',
    Fixed = 'FIXED',
    None = 'NONE',
}

export enum TaxAndFeeType {
    Tax = 'TAX',
    Fee = 'FEE',
}

export enum TaxAndFeeCalculationType {
    Percentage = 'PERCENTAGE',
    Fixed = 'FIXED'
}

export interface TaxAndFee {
    id?: number;
    name: string;
    rate: number | undefined;
    type: TaxAndFeeType;
    calculation_type: TaxAndFeeCalculationType;
    is_default: boolean;
    is_active: boolean;
    description?: string;
    account_id?: IdParam;
}

export interface InviteUserRequest {
    email: string;
    first_name: string;
    last_name: string;
    role: string;
}

export interface SortableItem {
    id: IdParam;
    order: number;
}

export interface QuestionAnswer {
    product_id?: number;
    product_title?: string;
    question_id: number;
    title: string;
    answer: string[] | string;
    text_answer: string;
    order_id: number;
    belongs_to: string;
    question_type: string;
    attendee_id?: number;
    attendee_public_id?: IdParam;
    first_name?: string;
    last_name?: string;
    question_answer_id?: IdParam;
    question_description?: string;
    question_required?: boolean;
    question_options?: string[];
    file_url?: string | null;
}

export interface QuestionFileAnswer {
    file_id: IdParam;
    name: string;
}

export interface QuestionAnswerFileUpload {
    id: IdParam;
    question_id: IdParam;
    original_filename: string;
    size: number;
}

export enum ReportTypes {
    ProductSales = 'product_sales',
    DailySales = 'daily_sales_report',
    PromoCodes = 'promo_codes_report',
    SalesBySource = 'sales_by_source_report',
}

export enum OrganizerReportTypes {
    RevenueSummary = 'revenue_summary',
    EventsPerformance = 'events_performance',
    TaxSummary = 'tax_summary',
    CheckInSummary = 'check_in_summary',
    PlatformFees = 'platform_fees',
}

export interface Webhook {
    id: IdParam;
    event_id: IdParam;
    url: string;
    secret: string;
    status: 'ENABLED' | 'PAUSED';
    event?: Event;
    event_types?: string[];
    last_response_code?: number;
    last_response_body?: string;
    last_triggered_at?: string | Date;
    logs?: WebhookLog[];
}

export interface WebhookLog {
    id: IdParam;
    webhook_id: IdParam;
    payload?: string;
    response_code?: number; // 0 = no response
    response_body?: string;
    event_type: string;
    created_at: string;
}

// Email Template Types
export type EmailTemplateType = 'order_confirmation' | 'attendee_ticket';
export type EmailTemplateEngine = 'liquid' | 'blade';

export interface EmailTemplate {
    id: number;
    account_id: number;
    organizer_id?: number;
    event_id?: number;
    template_type: EmailTemplateType;
    subject: string;
    body: string;
    cta?: {
        label: string;
        url_token: string;
    };
    engine: EmailTemplateEngine;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

export interface EmailTemplateToken {
    token: string;
    description: string;
    example: string;
}

export interface CreateEmailTemplateRequest {
    template_type: EmailTemplateType;
    subject: string;
    body: string;
    cta?: {
        label: string;
        url_token: string;
    };
}

export interface UpdateEmailTemplateRequest {
    subject: string;
    body: string;
    cta?: {
        label: string;
        url_token: string;
    };
    is_active?: boolean;
}

export interface PreviewEmailTemplateRequest {
    template_type: EmailTemplateType;
    subject: string;
    body: string;
    cta?: {
        label: string;
        url_token: string;
    };
}

export interface EmailTemplatePreview {
    subject: string;
    body: string;
    context: Record<string, any>;
}

export interface DefaultEmailTemplate {
    subject: string;
    body: string;
    cta?: {
        label: string;
        url_token: string;
    };
}

export enum WaitlistEntryStatus {
    Waiting = 'WAITING',
    Offered = 'OFFERED',
    Purchased = 'PURCHASED',
    Cancelled = 'CANCELLED',
    OfferExpired = 'OFFER_EXPIRED',
}

export interface WaitlistEntry {
    id?: number;
    event_id?: number;
    product_price_id?: number;
    product?: Product;
    product_price?: ProductPrice;
    email?: string;
    first_name?: string;
    last_name?: string;
    status?: WaitlistEntryStatus;
    position?: number;
    offered_at?: string;
    offer_expires_at?: string;
    purchased_at?: string;
    cancelled_at?: string;
    order_id?: number;
    order_short_id?: string;
    order_public_id?: string;
    cancel_token?: string;
    created_at?: string;
    updated_at?: string;
}

export interface JoinWaitlistRequest {
    product_price_id: number;
    email: string;
    first_name: string;
    last_name?: string;
    locale?: string;
}

export interface WaitlistProductStats {
    product_price_id: number;
    product_title: string;
    waiting: number;
    offered: number;
    available: number | null;
}

export interface WaitlistStats {
    total: number;
    waiting: number;
    offered: number;
    purchased: number;
    cancelled: number;
    expired: number;
    products: WaitlistProductStats[];
}

export interface KioskPrintRelayStatus {
    configured: boolean;
    last_seen_at: string | null;
    pending_jobs: number;
}

export interface KioskPrintRelayToken {
    relay_token: string;
    last_seen_at: string | null;
}

export interface EnqueueKioskPrintJobsResponse {
    job_ids: number[];
}

export type SmsConversationStatus = 'OPEN' | 'NEEDS_HUMAN' | 'CLOSED';
export type SmsMessageStatus = 'RECEIVED' | 'QUEUED' | 'PENDING' | 'SENT' | 'DELIVERED' | 'FAILED' | 'EXPIRED';
export type SmsMessageDirection = 'INBOUND' | 'OUTBOUND';
export type SmsMessageAuthorType = 'CONTACT' | 'HUMAN' | 'AGENT' | 'CAMPAIGN' | 'AUTOMATIC';
export type SmsConversationFilter = 'all' | 'unread' | 'needs_human';

export interface OrganizerSmsSettings {
    provider: string;
    api_key_configured: boolean;
    from_number: string | null;
    shared_line: boolean;
    webhook_url: string;
    webhook_signing_key: string;
    last_webhook_received_at: string | null;
    notify_on_inbound_sms: boolean;
    phone_status: SmsPhoneStatus | null;
    phone_status_changed_at: string | null;
    assistant_available: boolean;
    assistant_enabled: boolean;
    assistant_instructions: string | null;
    assistant_auto_send: boolean;
    assistant_holding_reply: string | null;
    assistant_holding_reply_enabled: boolean;
    live_chat_enabled: boolean;
    live_chat_relay_label: string | null;
    live_chat_relay_line: string;
    live_chat_style: LiveChatStyle;
    checkin_welcome_sms_enabled: boolean;
    checkin_welcome_sms_prompt: string | null;
    checkin_welcome_sms_templates: string | null;
    sms_default_reminders_enabled: boolean;
    organizer_assistant_enabled: boolean;
    organizer_assistant_instructions: string | null;
    history_import: SmsHistoryImport;
}

export type SmsHistoryImportStatus = 'RUNNING' | 'COMPLETED' | 'FAILED';

export interface SmsHistoryImport {
    status: SmsHistoryImportStatus | null;
    started_at: string | null;
    finished_at: string | null;
    imported_count: number | null;
    error: string | null;
}

export interface SmsReplyTemplate {
    id: number;
    name: string;
    content: string;
    updated_at: string;
}

export type SmsOptOutSource = 'KEYWORD' | 'MANUAL';

export interface SmsOptOut {
    id: number;
    phone: string;
    source: SmsOptOutSource;
    contact_name: string | null;
    created_at: string;
}

export type SmsAssistantOutcome = 'DRAFT' | 'NEEDS_HUMAN';

export interface SmsAssistantPreview {
    outcome: SmsAssistantOutcome;
    reply: string | null;
    reason: string | null;
}

export type SmsPhoneStatus = 'ONLINE' | 'OFFLINE';

export interface SmsConnectionTestResult {
    phone_numbers: string[];
    from_number_registered: boolean;
}

export interface SmsConversation {
    id: number;
    phone: string;
    contact_name: string | null;
    status: SmsConversationStatus;
    needs_human_reason: string | null;
    is_team: boolean;
    unread_count: number;
    last_message_at: string | null;
    last_message_preview: string | null;
    last_message_direction: SmsMessageDirection | null;
    draft_reply: string | null;
    draft_source: string | null;
    draft_generated_at: string | null;
    created_at: string;
}

export interface SmsMessage {
    id: number;
    direction: SmsMessageDirection;
    author_type: SmsMessageAuthorType;
    content: string;
    status: SmsMessageStatus;
    error_message: string | null;
    sent_by_user_id: number | null;
    auto_sent?: boolean;
    auto_reply_type?: 'ANSWER' | 'HOLDING' | null;
    channel?: 'WEB_CHAT' | null;
    provider_timestamp: string | null;
    created_at: string;
}

export type LiveChatAuthorType = 'VISITOR' | 'AGENT';

export interface LiveChatMessage {
    id: number;
    author_type: LiveChatAuthorType;
    content: string;
    created_at: string;
}

export type LiveChatStyle = 'CLASSIC' | 'MESSAGING';

export interface LiveChatConfig {
    enabled: boolean;
    organizer_name: string | null;
    relay_line: string | null;
    max_message_length: number;
    style?: LiveChatStyle;
    avatar_url?: string | null;
}

export interface LiveChatTurn {
    session_id: string;
    greeting: string | null;
    messages: LiveChatMessage[];
    ask_contact: boolean;
    contact_form_fallback: boolean;
    contact_given: boolean;
    refund_form: boolean;
    pending_question?: boolean;
}

export type LiveChatRefundStepName = 'code' | 'tickets' | 'pending' | 'refunded' | 'unavailable';

export interface LiveChatRefundTicket {
    attendee_id: number;
    name: string;
    category: string;
    amount: number;
    amount_minor: number;
}

export interface LiveChatRefundStep {
    step: LiveChatRefundStepName;
    messages: LiveChatMessage[];
    tickets: LiveChatRefundTicket[];
    max_tickets: number;
    currency: string | null;
    amount: number | null;
    error: string | null;
    attempts_left: number | null;
    ask_contact: boolean;
    contact_given: boolean;
}

export interface LiveChatSession {
    id: number;
    event_id: number | null;
    status: 'OPEN' | 'HANDED_OFF';
    visitor_phone: string | null;
    visitor_email: string | null;
    needs_follow_up: boolean;
    follow_up_reason: string | null;
    visitor_message_count: number;
    sms_conversation_id: number | null;
    contact_given_at: string | null;
    refunded_at: string | null;
    last_message_at: string | null;
    created_at: string;
    messages: LiveChatMessage[] | null;
}

export interface SmsCustomerOrder {
    order_id: number;
    public_id: string;
    event_id: number;
    event_title: string;
    event_start_date: string | null;
    first_name: string | null;
    last_name: string | null;
    email: string | null;
    total_gross: number;
    currency: string;
    status: string;
    created_at: string;
}

export type SmsCampaignRecipientStatus = 'PENDING' | 'SCHEDULED' | 'SENT' | 'DELIVERED' | 'FAILED' | 'EXPIRED';

export interface SmsCampaign {
    id: number;
    name: string;
    message: string | null;
    event_id: number | null;
    event_title: string | null;
    currency: string | null;
    recipients_count: number;
    pending_count: number;
    sent_count: number;
    delivered_count: number;
    failed_count: number;
    visits_count: number;
    clickers_count: number;
    orders_count: number;
    revenue_gross: number;
    created_at: string;
    type: SmsCampaignType;
    status: SmsCampaignStatus | null;
    scheduled_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    failure_reason: string | null;
    event_timezone: string | null;
    auto_key?: string | null;
    auto_edited?: boolean;
}

export type SmsCampaignType = 'IMPORTED' | 'REMINDER';

export type SmsCampaignStatus = 'SCHEDULED' | 'PROCESSING' | 'SENT' | 'FAILED' | 'CANCELLED';

export interface SmsReminderPreview {
    recipients_count: number;
    sms_count: number;
    sample_message: string | null;
    active_attendees_count: number;
    without_valid_phone_count: number;
    opted_out_count: number;
    shared_phone_count: number;
    personal_link_available: boolean;
    event_timezone: string;
    scheduled_at: string | null;
}

export interface SmsCampaignRecipient {
    id: number;
    phone: string;
    contact_name: string | null;
    status: SmsCampaignRecipientStatus;
    failure_reason: string | null;
    scheduled_at: string | null;
    sent_at: string | null;
    delivered_at: string | null;
    short_id: string | null;
    visits_count: number;
    has_clicked: boolean;
    orders_count: number;
    revenue_gross: number;
}

export interface SmsConversationDetail {
    conversation: SmsConversation;
    messages: SmsMessage[];
    customer_orders: SmsCustomerOrder[];
    opted_out: boolean;
}

export type OrganizerTeamContactRole = 'ORGANIZER' | 'COPY';

export interface OrganizerTeamContact {
    id: number;
    phone: string;
    name: string | null;
    role: OrganizerTeamContactRole;
    created_at: string;
}

export interface EventDailyReport {
    event_id: number;
    enabled: boolean;
    send_time: string;
    timezone: string;
    copy_supervisors: boolean;
    last_sent_on: string | null;
    team_contacts_count: number;
    preview: string;
}

export type RefundDecision = 'APPROVED' | 'REFUSED';

export interface RefundApproval {
    id: number;
    reference: string;
    status: string;
    decision: RefundDecision | null;
    organizer_id: number;
    organizer_name: string;
    event_title: string | null;
    customer_name: string;
    order_reference: string | null;
    amount: number;
    currency: string;
    amount_text: string;
    requested_at: string | null;
    decided_at: string | null;
    decided_by_channel: 'SMS' | 'LINK' | 'DASHBOARD' | null;
    decided_by_name: string | null;
    can_decide: boolean;
}

export type SocialNetwork = 'FACEBOOK' | 'INSTAGRAM';

export type SocialPostFormat = 'TEXT' | 'IMAGE' | 'CAROUSEL' | 'REEL' | 'STORY';

export type SocialMediaType = 'IMAGE' | 'VIDEO';

export type SocialPostStatus = 'DRAFT' | 'PENDING_APPROVAL' | 'SCHEDULED' | 'PUBLISHING' | 'PUBLISHED' | 'FAILED' | 'CANCELLED';

export type SocialPostSource = 'PICHA' | 'ASSISTANT';

export type SocialConnectionStatus = 'ACTIVE' | 'NEEDS_RECONNECT';

export interface SocialConnection {
    available: boolean;
    connected: boolean;
    status: SocialConnectionStatus | null;
    facebook_page_id: string | null;
    facebook_page_name: string | null;
    instagram_account_id: string | null;
    instagram_username: string | null;
    granted_scopes: string[];
    missing_scopes: string[];
    user_access_token_expires_at: string | null;
    last_error: string | null;
    last_error_at: string | null;
    connected_at: string | null;
}

export interface MetaPage {
    id: string;
    name: string;
    instagram_account_id: string | null;
    instagram_username: string | null;
    can_publish: boolean;
}

export interface SocialMediaItem {
    url: string;
    type: SocialMediaType;
    file_id?: number;
    thumbnail_url?: string | null;
}

export interface SocialPost {
    id: number;
    organizer_id: number;
    network: SocialNetwork;
    format: SocialPostFormat;
    caption: string | null;
    media: SocialMediaItem[];
    link: string | null;
    status: SocialPostStatus;
    source: SocialPostSource;
    scheduled_at: string | null;
    published_at: string | null;
    permalink: string | null;
    attempts: number;
    next_attempt_at: string | null;
    last_error: string | null;
    created_by_user_id: number | null;
    approved_by_user_id: number | null;
    approved_at: string | null;
    created_at: string;
    updated_at: string;
}

export type SocialMediaFileStatus = 'UPLOADING' | 'READY';

export interface SocialMediaFile {
    id: number;
    status: SocialMediaFileStatus;
    media_type: SocialMediaType;
    mime_type: string;
    original_name: string;
    size_bytes: number;
    received_bytes: number;
    chunk_size: number;
    width: number | null;
    height: number | null;
    duration_ms: number | null;
    video_codec: string | null;
    audio_codec: string | null;
    preview_url: string | null;
}

export type SocialFeedItemFormat = SocialPostFormat | 'VIDEO';

export interface SocialFeedItem {
    id: number;
    network: SocialNetwork;
    format: SocialFeedItemFormat;
    caption: string | null;
    media: SocialMediaItem[];
    permalink: string | null;
    published_at: string | null;
    like_count: number;
    comments_count: number;
    social_post_id: number | null;
}

export interface SocialFeedEntry {
    key: string;
    date: string;
    source: 'PICHA' | 'NETWORK';
    post: SocialPost | null;
    item: SocialFeedItem | null;
    latest_comments: SocialComment[];
}

export interface SocialComment {
    id: number;
    feed_item_id: number;
    network: SocialNetwork;
    thread_key: string;
    is_reply: boolean;
    author_name: string | null;
    message: string | null;
    like_count: number;
    is_from_page: boolean;
    replied_by_user_id: number | null;
    commented_at: string | null;
}
