import {useParams} from "react-router";
import {Skeleton} from "@mantine/core";
import {t} from "@lingui/macro";
import {ComponentType, useEffect, useMemo, useState} from "react";
import {EventDetailsForm} from "./Sections/EventDetailsForm";
import {LocationSettings} from "./Sections/LocationSettings";
import {HomepageAndCheckoutSettings} from "./Sections/HomepageAndCheckoutSettings";
import {EmailSettings} from "./Sections/EmailSettings";
import {SeoSettings} from "./Sections/SeoSettings";
import {MiscSettings} from "./Sections/MiscSettings";
import {PaymentAndInvoicingSettings} from "./Sections/PaymentSettings";
import {PlatformFeesSettings} from "./Sections/PlatformFeesSettings";
import {WaitlistSettings} from "./Sections/WaitlistSettings";
import {AttendanceResponseSettings} from "./Sections/AttendanceResponseSettings";
import {EmbeddedRegistrationSettings} from "./Sections/EmbeddedRegistrationSettings";
import {SmsConfirmationSettings} from "./Sections/SmsConfirmationSettings";
import {SmsCancellationSettings} from "./Sections/SmsCancellationSettings";
import {DailyReportSettings} from "./Sections/DailyReportSettings";
import {DangerZoneSettings} from "./Sections/DangerZoneSettings";
import {useGetAccount} from "../../../../queries/useGetAccount.ts";
import {useGetEvent} from "../../../../queries/useGetEvent.ts";
import classes from "./Settings.module.scss";

type TabId = 'essentials' | 'sales' | 'emails' | 'advanced';

interface Section {
    id: string;
    component: ComponentType;
}

const tabOfSection = (tabs: { id: TabId, sections: Section[] }[], sectionId: string) =>
    tabs.find((tab) => tab.id === sectionId || tab.sections.some((section) => section.id === sectionId))?.id;

export const Settings = () => {
    const {eventId} = useParams();
    const {data: event} = useGetEvent(eventId);
    const {data: account} = useGetAccount();
    const isSaasMode = account?.is_saas_mode_enabled;

    const tabs = useMemo(() => [
        {
            id: 'essentials' as TabId,
            label: t({message: 'Essentials', context: 'event settings tab'}),
            sections: [
                {id: 'event-details', component: EventDetailsForm},
                {id: 'location-settings', component: LocationSettings},
            ],
        },
        {
            id: 'sales' as TabId,
            label: t({message: 'Sales', context: 'event settings tab'}),
            sections: [
                {id: 'homepage-settings', component: HomepageAndCheckoutSettings},
                {id: 'embedded-registration-settings', component: EmbeddedRegistrationSettings},
                {id: 'payment-settings', component: PaymentAndInvoicingSettings},
                {id: 'waitlist-settings', component: WaitlistSettings},
                {id: 'attendance-response-settings', component: AttendanceResponseSettings},
            ],
        },
        {
            id: 'emails' as TabId,
            label: t({message: 'Messages', context: 'event settings tab'}),
            sections: [
                {id: 'email-settings', component: EmailSettings},
                {id: 'sms-confirmation-settings', component: SmsConfirmationSettings},
                {id: 'sms-cancellation-settings', component: SmsCancellationSettings},
                {id: 'daily-report-settings', component: DailyReportSettings},
            ],
        },
        {
            id: 'advanced' as TabId,
            label: t({message: 'Advanced', context: 'event settings tab'}),
            sections: [
                {id: 'seo-settings', component: SeoSettings},
                {id: 'misc-settings', component: MiscSettings},
                ...(isSaasMode ? [{id: 'platform-fees', component: PlatformFeesSettings}] : []),
                {id: 'danger-zone', component: DangerZoneSettings},
            ],
        },
    ], [isSaasMode]);

    const [activeTab, setActiveTab] = useState<TabId>('essentials');

    useEffect(() => {
        if (typeof window === 'undefined') return;
        const hash = window.location.hash.replace('#', '');
        const tab = hash ? tabOfSection(tabs, hash) : undefined;
        if (!tab) return;
        setActiveTab(tab);
        if (hash !== tab) {
            setTimeout(() => document.getElementById(hash)?.scrollIntoView({behavior: 'smooth'}), 150);
        }
    }, [tabs]);

    const selectTab = (tab: TabId) => {
        setActiveTab(tab);
        window.history.replaceState(null, '', `#${tab}`);
    };

    const current = tabs.find((tab) => tab.id === activeTab) ?? tabs[0];

    return (
        <div className={classes.page}>
            <header className={classes.header}>
                <span className={classes.kicker}>{t`Settings`}</span>
                {event
                    ? <h1 className={classes.title}>{event.title}</h1>
                    : <Skeleton height={40} width={260} radius="sm"/>}
            </header>

            <div className={classes.tabs} role="tablist">
                {tabs.map((tab) => (
                    <button key={tab.id} type="button" role="tab" aria-selected={tab.id === activeTab}
                            className={tab.id === activeTab ? classes.tabActive : classes.tab}
                            onClick={() => selectTab(tab.id)}>
                        {tab.label}
                    </button>
                ))}
            </div>

            <div role="tabpanel">
                {current.sections.map(({id, component: Component}) => (
                    <div key={id} id={id} className={classes.section}>
                        <Component/>
                    </div>
                ))}
            </div>
        </div>
    );
};

export default Settings;
