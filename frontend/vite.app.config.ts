import {defineConfig, mergeConfig, Plugin} from "vite";
import baseConfig from "./vite.config";

/** Serves the iOS Kiosk bundle's app.html as the index.html Capacitor loads. */
const appHtmlAsIndex = (): Plugin => ({
    name: "picha-app-html-as-index",
    enforce: "post",
    generateBundle(_, bundle) {
        const appHtml = bundle["app.html"];
        if (appHtml) {
            appHtml.fileName = "index.html";
        }
    },
});

export default mergeConfig(baseConfig, defineConfig({
    plugins: [appHtmlAsIndex()],
    build: {
        outDir: "dist-app",
        emptyOutDir: true,
        rollupOptions: {
            input: "app.html",
        },
    },
}));
