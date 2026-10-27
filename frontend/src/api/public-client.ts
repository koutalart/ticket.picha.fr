import axios from "axios";
import {isSsr} from "../utilites/helpers";
import {getConfig} from "../utilites/config";
import {customDomainStorageRewrite, rewriteStorageUrls} from "../utilites/customDomainStorage.ts";

export const publicApi = axios.create({
    withCredentials: true,
});

publicApi.interceptors.request.use((config) => {
    const baseUrl = isSsr()
        ? getConfig('VITE_API_URL_SERVER')
        : getConfig('VITE_API_URL_CLIENT');

    config.baseURL = `${baseUrl}/public`;
    return config;
}, (error) => {
    return Promise.reject(error);
});

publicApi.interceptors.response.use((response) => {
    const rewrite = customDomainStorageRewrite();
    if (rewrite && response.data) {
        response.data = rewriteStorageUrls(response.data, rewrite.from, rewrite.to);
    }
    return response;
});

axios.defaults.withCredentials = true;
