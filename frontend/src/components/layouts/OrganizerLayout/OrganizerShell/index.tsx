import {ReactNode, useEffect, useState} from "react";
import {NavLink, Outlet, useLocation} from "react-router";
import {UnstyledButton} from "@mantine/core";
import {IconChevronDown, IconDots, IconX} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import classNames from "classnames";
import ImpersonationBanner from "../../../common/ImpersonationBanner";
import {GlobalMenu} from "../../../common/GlobalMenu";
import {NavItem} from "../../AppLayout/types.ts";
import {getAppName, getLogoForDarkBackground, getLogoForLightBackground} from "../../../../utilites/branding.ts";
import classes from "./OrganizerShell.module.scss";

interface OrganizerShellProps {
    organizerName?: string;
    onSwitchOrganizer?: () => void;
    navItems: NavItem[];
    bottomItems: NavItem[];
    mobileTabs: NavItem[];
    headerActions?: ReactNode;
    sidebarFooter?: ReactNode;
}

const NavEntry = ({item, onNavigate, className, activeClassName}: {
    item: NavItem;
    onNavigate?: () => void;
    className: string;
    activeClassName: string;
}) => {
    const content = (
        <>
            {item.icon && <item.icon size={22} stroke={1.6}/>}
            <span>{item.label}</span>
            {item.badge !== undefined && item.badge !== null && <span className={classes.badge}>{item.badge}</span>}
        </>
    );

    if (!item.link) {
        return (
            <button type="button" className={className} onClick={() => {
                item.onClick?.();
                onNavigate?.();
            }}>
                {content}
            </button>
        );
    }

    if (item.link.startsWith('http') || item.link.startsWith('/events/')) {
        return (
            <a href={item.link} target="_blank" rel="noreferrer" className={className} onClick={onNavigate}>
                {content}
            </a>
        );
    }

    return (
        <NavLink
            to={item.link}
            onClick={onNavigate}
            className={({isActive}) => classNames(className, (item.isActive ? item.isActive(isActive) : isActive) && activeClassName)}
        >
            {content}
        </NavLink>
    );
};

export const OrganizerShell = ({
                                   organizerName,
                                   onSwitchOrganizer,
                                   navItems,
                                   bottomItems,
                                   mobileTabs,
                                   headerActions,
                                   sidebarFooter,
                               }: OrganizerShellProps) => {
    const [drawerOpen, setDrawerOpen] = useState(false);
    const location = useLocation();

    useEffect(() => setDrawerOpen(false), [location.pathname]);

    const visible = (items: NavItem[]) => items.filter((item) => !item.showWhen || item.showWhen());

    return (
        <>
            <ImpersonationBanner/>
            <div className={classes.shell}>
                <aside className={classNames(classes.sidebar, drawerOpen && classes.sidebarOpen)}>
                    <div className={classes.sidebarTop}>
                        <NavLink to="/manage/events" className={classes.logo}>
                            <img src={getLogoForDarkBackground()} alt={t`${getAppName()} logo`}/>
                        </NavLink>
                        <button type="button" className={classes.closeDrawer} aria-label={t`Close menu`}
                                onClick={() => setDrawerOpen(false)}>
                            <IconX size={22}/>
                        </button>
                    </div>

                    {organizerName && (
                        onSwitchOrganizer ? (
                            <UnstyledButton className={classes.organizerSwitch} onClick={onSwitchOrganizer}>
                                <span>{organizerName}</span>
                                <IconChevronDown size={16}/>
                            </UnstyledButton>
                        ) : (
                            <div className={classes.organizerSwitch}><span>{organizerName}</span></div>
                        )
                    )}

                    <nav className={classes.nav}>
                        {visible(navItems).map((item) => (
                            <NavEntry key={item.label} item={item} className={classes.navLink}
                                      activeClassName={classes.navLinkActive} onNavigate={() => setDrawerOpen(false)}/>
                        ))}
                    </nav>

                    <div className={classes.spacer}/>
                    {sidebarFooter}

                    <nav className={classes.bottomNav}>
                        {visible(bottomItems).map((item) => (
                            <NavEntry key={item.label} item={item} className={classes.bottomLink}
                                      activeClassName={classes.navLinkActive} onNavigate={() => setDrawerOpen(false)}/>
                        ))}
                    </nav>
                </aside>

                <div className={classes.mainColumn} id="app-manage-main">
                    <header className={classes.topbar}>
                        <NavLink to="/manage/events" className={classes.mobileLogo}>
                            <img src={getLogoForLightBackground()} alt={t`${getAppName()} logo`}/>
                        </NavLink>
                        <div className={classes.topbarActions}>
                            {headerActions && <div className={classes.headerActions}>{headerActions}</div>}
                            <GlobalMenu/>
                        </div>
                    </header>
                    <main className={classes.content}>
                        <Outlet/>
                    </main>
                </div>

                <nav className={classes.tabbar}>
                    {visible(mobileTabs).map((item) => (
                        <NavEntry key={item.label} item={item} className={classes.tab} activeClassName={classes.tabActive}/>
                    ))}
                    <button type="button" className={classes.tab} onClick={() => setDrawerOpen(true)}>
                        <IconDots size={22} stroke={1.6}/>
                        <span>{t`More`}</span>
                    </button>
                </nav>

                {drawerOpen && <div className={classes.overlay} onClick={() => setDrawerOpen(false)}/>}
            </div>
        </>
    );
};
