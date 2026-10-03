import {useEffect, useState} from "react";
import {Link, NavLink, useLocation} from "react-router";
import {t} from "@lingui/macro";
import {IconMenu2, IconShoppingBag, IconX} from "@tabler/icons-react";
import {getAppName, getLogoForLightBackground} from "../../../../utilites/branding.ts";
import {getConfig} from "../../../../utilites/config.ts";
import {getVisibleCaseStudies} from "../../case-studies/caseStudies.ts";
import classes from "./MarketingHeader.module.scss";

interface MarketingHeaderProps {
    isLanding?: boolean;
}

export const MarketingHeader = ({isLanding = false}: MarketingHeaderProps) => {
    const anchorBase = isLanding ? "" : "/";
    const shopUrl = getConfig("VITE_SHOP_URL") || "/location-materiel-evenementiel";
    const [menuOpen, setMenuOpen] = useState(false);
    const location = useLocation();

    useEffect(() => {
        setMenuOpen(false);
    }, [location.key]);

    useEffect(() => {
        if (!menuOpen) {
            return;
        }
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === "Escape") {
                setMenuOpen(false);
            }
        };
        window.addEventListener("keydown", closeOnEscape);
        return () => window.removeEventListener("keydown", closeOnEscape);
    }, [menuOpen]);

    const closeMenu = () => setMenuOpen(false);

    return (
        <header className={classes.header}>
            <div className={classes.inner}>
                <Link to="/" className={classes.logoLink}>
                    <img src={getLogoForLightBackground()} alt={getAppName()} className={classes.logo} width={132} height={40}/>
                </Link>
                <nav
                    id="marketing-navigation"
                    className={`${classes.nav} ${menuOpen ? classes.navOpen : ""}`}
                    aria-label={t`Main navigation`}
                >
                    <a href={`${anchorBase}#how-it-works`} onClick={closeMenu}>{t`How it works`}</a>
                    <a href={`${anchorBase}#use-cases`} onClick={closeMenu}>{t`Use cases`}</a>
                    {getVisibleCaseStudies().length > 0 && <NavLink to="/cas-clients">{t`Case studies`}</NavLink>}
                    <a href={`${anchorBase}#offers`} onClick={closeMenu}>{t`Offers`}</a>
                    <a href={`${anchorBase}#faq`} onClick={closeMenu}>{t`FAQ`}</a>
                    <a href={`${anchorBase}#demo`} className={classes.menuCta} onClick={closeMenu}>{t`Schedule my demo`}</a>
                </nav>
                <div className={classes.actions}>
                    <a href={shopUrl} className={classes.shopLink}>
                        <IconShoppingBag size={18} aria-hidden="true"/>
                        <span>{t`Shop`}</span>
                    </a>
                    <Link to="/auth/login" className={classes.loginLink}>{t`Log in`}</Link>
                    <a href={`${anchorBase}#demo`} className={classes.cta}>{t`Schedule my demo`}</a>
                    <button
                        type="button"
                        className={classes.menuButton}
                        aria-expanded={menuOpen}
                        aria-controls="marketing-navigation"
                        aria-label={menuOpen ? t`Close menu` : t`Open menu`}
                        onClick={() => setMenuOpen((open) => !open)}
                    >
                        {menuOpen ? <IconX size={24} aria-hidden="true"/> : <IconMenu2 size={24} aria-hidden="true"/>}
                    </button>
                </div>
            </div>
        </header>
    );
};
