import {useEffect, useLayoutEffect, useRef} from "react";
import {useLocation, useNavigationType} from "react-router-dom";

const savedPositions = new Map<string, number>();

const scrollToHash = (hash: string): boolean => {
    const target = hash ? document.getElementById(decodeURIComponent(hash.slice(1))) : null;
    target?.scrollIntoView();
    return target !== null;
};

export const ScrollToTop = () => {
    const location = useLocation();
    const navigationType = useNavigationType();
    const current = useRef<{ key: string; pathname: string } | null>(null);

    useEffect(() => {
        if ("scrollRestoration" in window.history) {
            window.history.scrollRestoration = "manual";
        }

        const rememberPosition = () => {
            if (current.current) {
                savedPositions.set(current.current.key, window.scrollY);
            }
        };
        window.addEventListener("scroll", rememberPosition, {passive: true});
        return () => window.removeEventListener("scroll", rememberPosition);
    }, []);

    useLayoutEffect(() => {
        const last = current.current;
        current.current = {key: location.key, pathname: location.pathname};

        if (!last || (last.pathname === location.pathname && location.hash !== "")) {
            scrollToHash(location.hash);
            return;
        }

        const savedPosition = navigationType === "POP" ? savedPositions.get(location.key) : undefined;
        if (savedPosition !== undefined) {
            window.scrollTo(0, savedPosition);
            return;
        }

        if (!scrollToHash(location.hash)) {
            window.scrollTo(0, 0);
        }
    }, [location.key, location.pathname, location.hash]);

    return null;
};
