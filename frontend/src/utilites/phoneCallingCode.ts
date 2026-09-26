import codes from "./phoneCallingCodes.json";

const CALLING_CODES = codes as Record<string, string>;

export const callingCodeFromIso2 = (iso2?: string | null): string => {
    if (!iso2) {
        return "";
    }

    return CALLING_CODES[iso2.trim().toUpperCase()] ?? "";
};
