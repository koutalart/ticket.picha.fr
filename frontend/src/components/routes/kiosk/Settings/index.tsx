import {Select, Switch, TextInput} from "@mantine/core";
import {t} from "@lingui/macro";
import {useKioskSettings} from "../../../../hooks/useKioskSettings.ts";
import {availableLocales, getLocaleName, SupportedLocales} from "../../../../locales.ts";
import classes from "../../../layouts/Kiosk/Kiosk.module.scss";

const KioskSettings = () => {
    const {settings, setSettings, isHydrated} = useKioskSettings();

    return (
        <div className={classes.page}>
            <h1>{t`Settings`}</h1>
            <p>{t`These preferences stay on this device. They are not saved to your account.`}</p>

            <TextInput
                mt="md"
                label={t`Station name`}
                disabled={!isHydrated}
                value={isHydrated ? settings.stationName : ''}
                onChange={(event) => setSettings({...settings, stationName: event.currentTarget.value})}
            />

            <Select
                mt="md"
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

            <Switch
                mt="lg"
                label={t`Send confirmation email by default`}
                disabled={!isHydrated}
                checked={isHydrated ? settings.sendConfirmationEmail : false}
                onChange={(event) => setSettings({
                    ...settings,
                    sendConfirmationEmail: event.currentTarget.checked,
                })}
            />
        </div>
    );
};

export default KioskSettings;
