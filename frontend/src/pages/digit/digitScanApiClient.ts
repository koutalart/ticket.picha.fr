import axios, {AxiosInstance} from "axios";
import {isSsr} from "../../utilites/helpers";
import {getConfig} from "../../utilites/config";

/**
 * Dedicated, minimal API client for the DIGIT Bracelets scan endpoints
 * (backend/modules/digit/routes/scan.php - "/digit/scan/bracelets/...").
 * Deliberately NOT the native `publicApi` client (which targets
 * `${BASE_URL}/public` and carries native session/JWT concerns) - these
 * routes live outside that group and are authenticated by a device token
 * instead (see AuthenticateScanDevice, unchanged, Module 3).
 *
 * The device token is a per-device secret generated once via
 * `php artisan digit:scan:device:create` and pasted into this app by
 * staff on first use (see DeviceTokenGate). It never leaves this device
 * except as the Authorization header on these requests - it is not a
 * cryptographic secret used in the QR signing (that secret,
 * digit_event_security_keys, never leaves the server at all).
 */
const DEVICE_TOKEN_STORAGE_KEY = "digit_scan_device_token";

export const getStoredDeviceToken = (): string | null => {
    if (typeof window === "undefined") {
        return null;
    }
    return window.localStorage.getItem(DEVICE_TOKEN_STORAGE_KEY);
};

export const storeDeviceToken = (token: string): void => {
    window.localStorage.setItem(DEVICE_TOKEN_STORAGE_KEY, token);
};

export const clearDeviceToken = (): void => {
    window.localStorage.removeItem(DEVICE_TOKEN_STORAGE_KEY);
};

const digitApi: AxiosInstance = axios.create({
    withCredentials: false,
});

digitApi.interceptors.request.use((config) => {
    const baseUrl = isSsr()
        ? getConfig('VITE_API_URL_SERVER')
        : getConfig('VITE_API_URL_CLIENT');

    config.baseURL = baseUrl;

    const token = getStoredDeviceToken();
    if (token) {
        config.headers = config.headers ?? {};
        config.headers['Authorization'] = `Bearer ${token}`;
    }

    return config;
});

export type BraceletScanOutcome = 'recorded' | 'duplicate' | 'duplicate_retry' | 'rejected';

export interface BraceletScanResult {
    attendee_id: number;
    result: BraceletScanOutcome;
    attendee_check_in_id: number | null;
    messages: string[];
    first_scanned_by_device?: string | null;
    first_scanned_at?: string | null;
    first_scanned_check_in_list_name?: string | null;
}

export const digitScanClient = {
    scanBracelet: async (checkInListShortId: string, payload: string): Promise<BraceletScanResult> => {
        const response = await digitApi.post<BraceletScanResult>(
            `/digit/scan/bracelets/${checkInListShortId}/check-ins`,
            {payload, action: 'check-in'},
        );
        return response.data;
    },
};
