import type {createBrowserRouter} from "react-router-dom";

type Router = ReturnType<typeof createBrowserRouter>;

const scrollToHash = (hash: string): boolean => {
    const target = hash ? document.getElementById(decodeURIComponent(hash.slice(1))) : null;
    target?.scrollIntoView();
    return target !== null;
};

const afterRender = (callback: () => void) => requestAnimationFrame(() => requestAnimationFrame(callback));

const scrollToPosition = (position: number, deadline = performance.now() + 1000) => {
    const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
    if (maxScroll >= position || performance.now() > deadline) {
        window.scrollTo(0, position);
        return;
    }
    requestAnimationFrame(() => scrollToPosition(position, deadline));
};

export const setupScrollManagement = (router: Router) => {
    const savedPositions = new Map<string, number>();
    let current = {key: router.state.location.key, pathname: router.state.location.pathname};

    if ("scrollRestoration" in window.history) {
        window.history.scrollRestoration = "manual";
    }

    window.addEventListener("scroll", () => savedPositions.set(current.key, window.scrollY), {passive: true});

    router.subscribe((state) => {
        const {location, historyAction} = state;
        if (state.navigation.state !== "idle" || location.key === current.key) {
            return;
        }

        const isSamePageAnchor = current.pathname === location.pathname && location.hash !== "";
        current = {key: location.key, pathname: location.pathname};

        afterRender(() => {
            if (isSamePageAnchor) {
                scrollToHash(location.hash);
                return;
            }

            const savedPosition = historyAction === "POP" ? savedPositions.get(location.key) : undefined;
            if (savedPosition !== undefined) {
                scrollToPosition(savedPosition);
                return;
            }

            if (!scrollToHash(location.hash)) {
                window.scrollTo(0, 0);
            }
        });
    });
};
