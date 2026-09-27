import {Navigate, Outlet} from "react-router";
import classes from "./Auth.module.scss";
import {t} from "@lingui/macro";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {PoweredByFooter} from "../../common/PoweredByFooter";
import {LanguageSwitcher} from "../../common/LanguageSwitcher";
import {
    IconChartBar,
    IconCreditCard,
    IconDeviceMobile,
    IconPalette,
    IconQrcode,
    IconShieldCheck,
    IconSparkles,
    IconTicket,
    IconUsers,
} from '@tabler/icons-react';
import {useCallback, useEffect, useRef, useState} from "react";
import {showInfo} from "../../../utilites/notifications.tsx";
import {getAppName, getLogoForLightBackground} from "../../../utilites/branding.ts";

const allFeatures = [
    {
        icon: IconTicket,
        title: t`Flexible Ticketing`,
        description: t`Paid, free, tiered pricing, and donation-based tickets`
    },
    {
        icon: IconQrcode,
        title: t`QR Code Check-in`,
        description: t`Mobile scanner with offline support and real-time tracking`
    },
    {
        icon: IconCreditCard,
        title: t`Instant Payouts`,
        description: t`Get paid immediately via Stripe Connect`
    },
    {
        icon: IconChartBar,
        title: t`Real-Time Analytics`,
        description: t`Track sales, revenue, and attendance with detailed reports`
    },
    {
        icon: IconPalette,
        title: t`Custom Branding`,
        description: t`Your logo, colors, and style on every page`
    },
    {
        icon: IconDeviceMobile,
        title: t`Mobile Optimized`,
        description: t`Beautiful checkout experience on any device`
    },
    {
        icon: IconUsers,
        title: t`Team Management`,
        description: t`Invite unlimited team members with custom roles`
    },
    {
        icon: IconShieldCheck,
        title: t`Data Ownership`,
        description: t`You own 100% of your attendee data, always`
    },
];

const FeaturePanel = () => {
    const [selectedFeatures, setSelectedFeatures] = useState(() => allFeatures.slice(0, 4));

    useEffect(() => {
        setSelectedFeatures([...allFeatures].sort(() => 0.5 - Math.random()).slice(0, 4));
    }, []);

    return (
        <div className={classes.rightPanel}>
            <div className={classes.backgroundImage} />
            <div className={classes.backgroundOverlay} />
            <div className={classes.gridPattern} />
            <div className={`${classes.glowEffect} ${classes.glowTop}`} />
            <div className={`${classes.glowEffect} ${classes.glowBottom}`} />

            <div className={classes.overlay}>
                <div className={classes.content}>
                    <div className={classes.badge}>
                        <IconSparkles size={14} />
                        <span>{t`Event Management Platform`}</span>
                    </div>

                    <div className={classes.featureGrid}>
                        {selectedFeatures.map((feature, index) => {
                            const Icon = feature.icon;
                            return (
                                <div key={index} className={classes.feature}>
                                    <div className={classes.featureIcon}>
                                        <Icon size={18} />
                                    </div>
                                    <div className={classes.featureText}>
                                        <h3>{feature.title}</h3>
                                        <p>{feature.description}</p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>
    );
};

const AuthLayout = () => {
    const me = useGetMe();
    const clickCountRef = useRef(0);
    const clickTimerRef = useRef<ReturnType<typeof setTimeout>>();

    const handleLogoClick = useCallback(() => {
        clickCountRef.current += 1;
        clearTimeout(clickTimerRef.current);
        clickTimerRef.current = setTimeout(() => { clickCountRef.current = 0; }, 2000);

        if (clickCountRef.current >= 5) {
            clickCountRef.current = 0;
            showInfo(`${getAppName()} v${__APP_VERSION__}`);
        }
    }, []);

    if (me.isSuccess) {
        return <Navigate to={me.data?.role === 'BOX_OFFICE_OPERATOR' ? '/kiosk' : '/manage/events'} />
    }

    return (
        <div className={classes.authLayout}>
            <div className={classes.splitLayout}>
                <div className={classes.leftPanel}>
                    <main className={classes.container}>
                        <div className={classes.logo} onClick={handleLogoClick} style={{cursor: 'pointer'}}>
                            <img
                                src={getLogoForLightBackground()}
                                alt={t`${getAppName()} logo`}
                            />
                        </div>
                        <div className={classes.wrapper}>
                            <Outlet />
                            <PoweredByFooter />
                            <div className={classes.languageSwitcher}>
                                <LanguageSwitcher />
                            </div>
                        </div>
                    </main>
                </div>

                <FeaturePanel />
            </div>
        </div>
    );
};

export default AuthLayout;
