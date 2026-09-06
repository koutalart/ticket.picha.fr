import {useEffect, useState} from "react";
import {Select, Switch, TextInput} from "@mantine/core";
import {IconChevronRight} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {useParams} from "react-router";
import {useKioskSettings} from "../../../../hooks/useKioskSettings.ts";
import {useGetMe} from "../../../../queries/useGetMe.ts";
import {useGetBoxOfficeContext} from "../../../../queries/useGetBoxOfficeContext.ts";
import {availableLocales, getLocaleName, SupportedLocales} from "../../../../locales.ts";
import {formatDateForUser, prettyDate} from "../../../../utilites/dates.ts";
import {isSsr} from "../../../../utilites/helpers.ts";
import {KIOSK_CONNECTED_AT_KEY} from "../../../../utilites/kioskAuth.ts";
import classes from "../../../layouts/Kiosk/Kiosk.module.scss";

type SettingsSection = 'printing' | 'options' | 'information';

const KioskSettings = () => {
    const {eventId} = useParams();
    const {settings, setSettings, isHydrated} = useKioskSettings();
    const me = useGetMe();
    const context = useGetBoxOfficeContext();
    const [section, setSection] = useState<SettingsSection>('printing');
    const currentEvent = (context.data?.data ?? []).find((event) => String(event.id) === String(eventId));
    const timezone = currentEvent?.timezone || me.data?.timezone || 'UTC';
    const [nowIso, setNowIso] = useState(() => new Date().toISOString());
    const [connectedAt, setConnectedAt] = useState<string | null>(null);

    useEffect(() => {
        if (isSsr()) {
            return;
        }
        const id = window.setInterval(() => setNowIso(new Date().toISOString()), 1000);
        return () => window.clearInterval(id);
    }, []);

    useEffect(() => {
        if (isSsr()) {
            return;
        }
        setConnectedAt(window.sessionStorage.getItem(KIOSK_CONNECTED_AT_KEY) || me.data?.last_login_at || null);
    }, [me.data?.last_login_at]);

    const navItems: { id: SettingsSection; label: string }[] = [
        {id: 'printing', label: t`Printing`},
        {id: 'options', label: t`Options`},
        {id: 'information', label: t`Information`},
    ];

    return (
        <div className={classes.settingsLayout}>
            <nav className={classes.settingsNav} aria-label={t`Settings`}>
                {navItems.map((item) => (
                    <button
                        key={item.id}
                        type="button"
                        className={`${classes.settingsNavItem} ${section === item.id ? classes.settingsNavItemActive : ''}`}
                        onClick={() => setSection(item.id)}
                    >
                        {item.label}
                        <IconChevronRight size={16}/>
                    </button>
                ))}
            </nav>
            <div className={classes.settingsPane}>
                <p className={classes.infoBanner}>
                    {t`These settings will apply only to this sales station.`}
                </p>

                {section === 'printing' && (
                    <>
                        <div className={classes.choiceGrid}>
                            <button
                                type="button"
                                disabled={!isHydrated}
                                className={`${classes.choiceCard} ${settings.printOutput === 'none' ? classes.choiceCardActive : ''}`}
                                onClick={() => setSettings({...settings, printOutput: 'none'})}
                            >
                                {t`No printing`}
                            </button>
                            <button
                                type="button"
                                disabled={!isHydrated}
                                className={`${classes.choiceCard} ${settings.printOutput === 'zebra' ? classes.choiceCardActive : ''}`}
                                onClick={() => setSettings({...settings, printOutput: 'zebra'})}
                            >
                                {t`Zebra ZD621 (silent)`}
                            </button>
                        </div>
                        {settings.printOutput === 'zebra' && (
                            <TextInput
                                label={t`Printer IP address`}
                                description={t`Private LAN address of the Zebra (port 9100).`}
                                disabled={!isHydrated}
                                placeholder="192.168.1.50"
                                value={isHydrated ? settings.zebraPrinterHost : ''}
                                onChange={(event) => setSettings({
                                    ...settings,
                                    zebraPrinterHost: event.currentTarget.value.trim(),
                                })}
                            />
                        )}
                    </>
                )}

                {section === 'options' && (
                    <>
                        <div className={classes.optionRow}>
                            <Switch
                                disabled={!isHydrated}
                                checked={isHydrated ? settings.sendConfirmationEmail : false}
                                onChange={(event) => setSettings({
                                    ...settings,
                                    sendConfirmationEmail: event.currentTarget.checked,
                                })}
                            />
                            <span>{t`Send tickets by email when possible`}</span>
                        </div>
                        <div className={classes.optionRow}>
                            <TextInput
                                style={{flex: 1}}
                                label={t`Station name`}
                                disabled={!isHydrated}
                                value={isHydrated ? settings.stationName : ''}
                                onChange={(event) => setSettings({...settings, stationName: event.currentTarget.value})}
                            />
                        </div>
                        <div className={classes.optionRow}>
                            <Select
                                style={{flex: 1}}
                                label={t`Default ticket language`}
                                disabled={!isHydrated}
                                clearable
                                data={availableLocales.map((locale) => ({
                                    value: locale,
                                    label: getLocaleName(locale as SupportedLocales),
                                }))}
                                value={isHydrated ? (settings.defaultTicketLocale || null) : null}
                                onChange={(value) => setSettings({
                                    ...settings,
                                    defaultTicketLocale: (value as SupportedLocales | null) ?? '',
                                })}
                            />
                        </div>
                    </>
                )}

                {section === 'information' && (
                    <div className={classes.infoList}>
                        <div className={classes.infoRow}>
                            <span className={classes.infoLabel}>{t`Operator`}</span>
                            <span>{me.data?.full_name} {me.data?.email ? `(${me.data.email})` : ''}</span>
                        </div>
                        <div className={classes.infoRow}>
                            <span className={classes.infoLabel}>{t`Event`}</span>
                            <span>{currentEvent?.title ?? '—'}</span>
                        </div>
                        <div className={classes.infoRow}>
                            <span className={classes.infoLabel}>{t`Station name`}</span>
                            <span>{isHydrated && settings.stationName ? settings.stationName : '—'}</span>
                        </div>
                        <div className={classes.infoRow}>
                            <span className={classes.infoLabel}>{t`Connected at`}</span>
                            <span>{connectedAt ? prettyDate(connectedAt, timezone, true) : '—'}</span>
                        </div>
                        <div className={classes.infoRow}>
                            <span className={classes.infoLabel}>{t`Date and time`}</span>
                            <span>{formatDateForUser(nowIso, 'fullDateTime', timezone)}</span>
                        </div>
                        <div className={classes.infoRow}>
                            <span className={classes.infoLabel}>{t`Session`}</span>
                            <span>{t`No cash session on this kiosk`}</span>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

export default KioskSettings;
