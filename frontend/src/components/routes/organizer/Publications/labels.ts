import {t} from "@lingui/macro";
import {SocialPostFormat, SocialPostStatus} from "../../../../types.ts";

export const postStatusLabel = (status: SocialPostStatus): string => {
    switch (status) {
        case 'DRAFT':
            return t`Draft`;
        case 'PENDING_APPROVAL':
            return t`To approve`;
        case 'SCHEDULED':
            return t`Scheduled`;
        case 'PUBLISHING':
            return t`Publishing`;
        case 'PUBLISHED':
            return t`Published`;
        case 'FAILED':
            return t`Failed`;
        case 'CANCELLED':
            return t`Cancelled`;
    }
};

export const postStatusColor = (status: SocialPostStatus): string => {
    switch (status) {
        case 'PENDING_APPROVAL':
            return 'orange';
        case 'SCHEDULED':
        case 'PUBLISHING':
            return 'blue';
        case 'PUBLISHED':
            return 'green';
        case 'FAILED':
            return 'red';
        default:
            return 'gray';
    }
};

export const postFormatLabel = (format: SocialPostFormat): string => {
    switch (format) {
        case 'TEXT':
            return t`Text`;
        case 'IMAGE':
            return t`Image`;
        case 'CAROUSEL':
            return t`Carousel`;
        case 'REEL':
            return t`Reel`;
        case 'STORY':
            return t`Story`;
    }
};
