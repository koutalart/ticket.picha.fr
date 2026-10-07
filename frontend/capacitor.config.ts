import type {CapacitorConfig} from "@capacitor/cli";

const config: CapacitorConfig = {
    appId: "fr.picha.ticket.kiosk",
    appName: "PICHA Kiosk",
    webDir: "dist-app",
    ios: {
        contentInset: "never",
        scheme: "PICHA Kiosk",
    },
};

export default config;
