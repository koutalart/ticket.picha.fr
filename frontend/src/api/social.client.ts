import {api} from "./client";
import {
    GenericDataResponse,
    GenericPaginatedResponse,
    IdParam,
    MetaPage,
    SocialComment,
    SocialConnection,
    SocialFeedEntry,
    SocialFeedItem,
    SocialMediaFile,
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

export interface SocialFeedResponse {
    data: SocialFeedEntry[];
    meta: { has_more: boolean, limit: number };
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
    feed: async (organizerId: IdParam, limit: number) => {
        const response = await api.get<SocialFeedResponse>(`organizers/${organizerId}/social/feed`, {params: {limit}});
        return response.data;
    },
    stories: async (organizerId: IdParam) => {
        const response = await api.get<GenericDataResponse<SocialFeedItem[]>>(`organizers/${organizerId}/social/stories`);
        return response.data;
    },
    comments: async (organizerId: IdParam, feedItemId: IdParam) => {
        const response = await api.get<GenericDataResponse<SocialComment[]>>(`organizers/${organizerId}/social/feed-items/${feedItemId}/comments`);
        return response.data;
    },
    suggestReply: async (organizerId: IdParam, commentId: IdParam) => {
        const response = await api.post<GenericDataResponse<{ message: string }>>(`organizers/${organizerId}/social/comments/${commentId}/suggest-reply`);
        return response.data;
    },
    reply: async (organizerId: IdParam, commentId: IdParam, message: string) => {
        const response = await api.post<GenericDataResponse<SocialComment>>(`organizers/${organizerId}/social/comments/${commentId}/reply`, {message});
        return response.data;
    },
    /**
     * Sends the file in chunks (the server refuses requests over 20 MB), then lets the server check it.
     */
    uploadMedia: async (
        organizerId: IdParam,
        file: File,
        format: SocialPostFormat | null,
        onProgress: (ratio: number) => void,
    ): Promise<SocialMediaFile> => {
        const started = await api.post<GenericDataResponse<SocialMediaFile>>(`organizers/${organizerId}/social/media/uploads`, {
            file_name: file.name,
            mime_type: file.type || 'application/octet-stream',
            size: file.size,
        });
        const media = started.data.data;
        const chunkSize = media.chunk_size;
        const chunks = Math.max(1, Math.ceil(file.size / chunkSize));

        for (let index = 0; index < chunks; index++) {
            const body = new FormData();
            body.append('index', String(index));
            body.append('chunk', file.slice(index * chunkSize, (index + 1) * chunkSize), 'chunk');
            await api.post(`organizers/${organizerId}/social/media/uploads/${media.id}/chunks`, body, {
                headers: {'Content-Type': 'multipart/form-data'},
                onUploadProgress: (event) => onProgress((index * chunkSize + (event.loaded || 0)) / file.size),
            });
        }

        onProgress(1);

        const completed = await api.post<GenericDataResponse<SocialMediaFile>>(`organizers/${organizerId}/social/media/uploads/${media.id}/complete`, {format});

        return completed.data.data;
    },
};
