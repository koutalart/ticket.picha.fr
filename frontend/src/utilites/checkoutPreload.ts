import {isSsr} from "./helpers.ts";

const STRIPE_ORIGINS = ['https://js.stripe.com', 'https://api.stripe.com', 'https://m.stripe.network'];

let paymentStepPreloaded = false;

export const preloadPaymentStep = () => {
    if (isSsr() || paymentStepPreloaded) {
        return;
    }
    paymentStepPreloaded = true;

    STRIPE_ORIGINS.forEach((origin) => {
        if (document.head.querySelector(`link[rel="preconnect"][href="${origin}"]`)) {
            return;
        }
        const link = document.createElement('link');
        link.rel = 'preconnect';
        link.href = origin;
        link.crossOrigin = 'anonymous';
        document.head.appendChild(link);
    });

    import("../components/routes/product-widget/Payment").catch(() => {
        paymentStepPreloaded = false;
    });
};
