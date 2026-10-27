export type {TrackingPixelConfig} from '../../types';

export interface PageViewData {
    url: string;
    title: string;
    referrer?: string;
    eventId?: string;
}

export interface TrackingEventData {
    eventName: string;
    value?: number;
    currency?: string;
    contentName?: string;
    contentId?: string | number;
    eventId?: string;
    [key: string]: unknown;
}

export interface TrackingPixelPlugin {
    name: string;
    initialize(pixelId: string): void;
    pageView(data: PageViewData): void;
    trackEvent(data: TrackingEventData): void;
    cleanup?(): void;
}
