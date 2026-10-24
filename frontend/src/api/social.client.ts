import {api} from "./client";
import {
    GenericDataResponse,
    GenericPaginatedResponse,
    IdParam,
    MetaPage,
    SocialConnection,
    SocialMediaItem,
    SocialNetwork,
    SocialPost,
    SocialPostFormat,
    SocialPostStatus,
} from "../types";

export interface SocialPostsParams {
    status?: SocialPostStatus;
    page?: number;
}

export interface CreateSocialPostRequest {
    networks: SocialNetwork[];
    format: SocialPostFormat;
    caption?: string | null;
    media?: SocialMediaItem[];
    link?: string | null;
    scheduled_at?: string | null;
    as_draft?: boolean;
}

export interface UpdateSocialPostRequest {
    caption?: string | null;
    media?: SocialMediaItem[];
    link?: string | null;
    scheduled_at?: string | null;
}

export const socialClient = {
    connection: async (organizerId: IdParam) => {
        const response = await api.get<GenericDataResponse<SocialConnection>>(`organizers/${organizerId}/social/connection`);
        return response.data;
    },
    loginUrl: async (organizerId: IdParam) => {
        const response = await api.post<GenericDataResponse<{ url: string }>>(`organizers/${organizerId}/social/meta/login-url`);
        return response.data;
    },
    pendingPages: async (organizerId: IdParam, selectionKey: string) => {
        const response = await api.get<GenericDataResponse<MetaPage[]>>(`organizers/${organizerId}/social/meta/pending-pages/${selectionKey}`);
        return response.data;
    },
    connect: async (organizerId: IdParam, selectionKey: string, pageId: string) => {
        const response = await api.post<GenericDataResponse<SocialConnection>>(`organizers/${organizerId}/social/connection`, {
            selection_key: selectionKey,
            page_id: pageId,
        });
        return response.data;
    },
    disconnect: async (organizerId: IdParam) => {
        await api.delete(`organizers/${organizerId}/social/connection`);
    },
    posts: async (organizerId: IdParam, params: SocialPostsParams) => {
        const response = await api.get<GenericPaginatedResponse<SocialPost>>(`organizers/${organizerId}/social/posts`, {params});
        return response.data;
    },
    createPosts: async (organizerId: IdParam, post: CreateSocialPostRequest) => {
        const response = await api.post<GenericDataResponse<SocialPost[]>>(`organizers/${organizerId}/social/posts`, post);
        return response.data;
    },
    updatePost: async (organizerId: IdParam, postId: IdParam, post: UpdateSocialPostRequest) => {
        const response = await api.put<GenericDataResponse<SocialPost>>(`organizers/${organizerId}/social/posts/${postId}`, post);
        return response.data;
    },
    schedulePost: async (organizerId: IdParam, postId: IdParam) => {
        const response = await api.post<GenericDataResponse<SocialPost>>(`organizers/${organizerId}/social/posts/${postId}/schedule`);
        return response.data;
    },
    cancelPost: async (organizerId: IdParam, postId: IdParam) => {
        const response = await api.post<GenericDataResponse<SocialPost>>(`organizers/${organizerId}/social/posts/${postId}/cancel`);
        return response.data;
    },
    deletePost: async (organizerId: IdParam, postId: IdParam) => {
        await api.delete(`organizers/${organizerId}/social/posts/${postId}`);
    },
};
