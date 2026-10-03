import {setAuthToken} from "../utilites/apiClient.ts";

const NATIVE_AUTH_TOKEN_KEY = 'picha_native_auth_token';

interface CapacitorGlobal {
    isNativePlatform?: () => boolean;
}

/**
 * True inside the PICHA Kiosk iOS app (Capacitor). The app's web view runs on
 * capacitor://localhost, where the API's cross-site auth cookie is blocked,
 * so the JWT is kept on the device and sent as a Bearer header instead.
 */
export const isNativeApp = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }
    const capacitor = (window as unknown as { Capacitor?: CapacitorGlobal }).Capacitor;
    return capacitor?.isNativePlatform?.() === true;
};

export const restoreNativeAuthToken = () => {
    if (!isNativeApp()) {
        return;
    }
    setAuthToken(window.localStorage.getItem(NATIVE_AUTH_TOKEN_KEY));
};

export const storeNativeAuthToken = (token?: string | null) => {
    if (!isNativeApp()) {
        return;
    }
    if (token) {
        window.localStorage.setItem(NATIVE_AUTH_TOKEN_KEY, token);
    } else {
        window.localStorage.removeItem(NATIVE_AUTH_TOKEN_KEY);
    }
    setAuthToken(token);
};
