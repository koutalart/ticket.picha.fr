import {ReactNode, useEffect, useState} from "react";
import {NavLink, Outlet, useLocation} from "react-router";
import {UnstyledButton} from "@mantine/core";
import {IconArrowLeft, IconChevronDown, IconChevronLeft, IconDots, IconX} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import classNames from "classnames";
import ImpersonationBanner from "../../common/ImpersonationBanner";
import {GlobalMenu} from "../../common/GlobalMenu";
import {NavItem} from "../AppLayout/types.ts";
import {getAppName, getLogoForDarkBackground, getLogoForLightBackground} from "../../../utilites/branding.ts";
import classes from "./ManageShell.module.scss";

interface ManageShellProps {
    organizerName?: string;
    onSwitchOrganizer?: () => void;
    backLink?: { to: string, label: string };
    breadcrumbs?: ReactNode;
    navItems: NavItem[];
    moreItems?: NavItem[];
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

    if (item.link.startsWith('http') || item.link.startsWith('/events/') || item.link.startsWith('/event/')) {
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

const isHeading = (item: NavItem) => !item.link && !item.onClick;

export const ManageShell = ({
                                organizerName,
                                onSwitchOrganizer,
                                backLink,
                                breadcrumbs,
                                navItems,
                                moreItems = [],
                                bottomItems,
                                mobileTabs,
                                headerActions,
                                sidebarFooter,
                            }: ManageShellProps) => {
    const location = useLocation();
    const [drawerOpen, setDrawerOpen] = useState(false);
    const moreIsActive = moreItems.some((item) => item.link && location.pathname.includes(`/${item.link}`));
    const [moreOpen, setMoreOpen] = useState(moreIsActive);

    useEffect(() => setDrawerOpen(false), [location.pathname]);
    useEffect(() => {
        if (moreIsActive) setMoreOpen(true);
    }, [moreIsActive]);

    const visible = (items: NavItem[]) => items.filter((item) => !item.showWhen || item.showWhen());

    const renderNav = (items: NavItem[]) => visible(items).map((item, index) => {
        if (isHeading(item)) {
            return item.label
                ? <div key={`h-${index}`} className={classes.navHeading}>{item.label}</div>
                : <div key={`d-${index}`} className={classes.navDivider}/>;
        }
        return (
            <NavEntry key={item.label} item={item} className={classes.navLink}
                      activeClassName={classes.navLinkActive} onNavigate={() => setDrawerOpen(false)}/>
        );
    });

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

                    {backLink && (
                        <NavLink to={backLink.to} className={classes.backLink}>
                            <IconArrowLeft size={18}/> <span>{backLink.label}</span>
                        </NavLink>
                    )}

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
                        {renderNav(navItems)}
                        {visible(moreItems).length > 0 && (
                            <>
                                <div className={classes.navDivider}/>
                                <button type="button" className={classes.navLink} aria-expanded={moreOpen}
                                        onClick={() => setMoreOpen((open) => !open)}>
                                    <IconDots size={22} stroke={1.6}/>
                                    <span>{t`More`}</span>
                                    <IconChevronDown size={16}
                                                     className={classNames(classes.moreChevron, moreOpen && classes.moreChevronOpen)}/>
                                </button>
                                {moreOpen && <div className={classes.moreItems}>{renderNav(moreItems)}</div>}
                            </>
                        )}
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
                    <header className={classNames(classes.topbar, breadcrumbs && classes.topbarWithBreadcrumbs)}>
                        {breadcrumbs && <div className={classes.breadcrumbs}>{breadcrumbs}</div>}
                        <NavLink to="/manage/events" className={classes.mobileLogo}>
                            <img src={getLogoForLightBackground()} alt={t`${getAppName()} logo`}/>
                        </NavLink>
                        <div className={classes.topbarActions}>
                            {headerActions && <div className={classes.headerActions}>{headerActions}</div>}
                            <GlobalMenu/>
                        </div>
                    </header>
                    <main className={classes.content}>
                        {backLink && (
                            <NavLink to={backLink.to} className={classes.mobileBack}>
                                <IconChevronLeft size={18}/> {backLink.label}
                            </NavLink>
                        )}
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
