import {useEffect, useState} from "react";
import {I18n, i18n as pageI18n, setupI18n} from "@lingui/core";
import {CustomerAddressForm, getCustomerAddressForm, normalizeCustomerAddressForm} from "../../../locales.ts";
import {Organizer} from "../../../types.ts";

// The organizers write to their public in French: the chat speaks French whatever the language of the browser
// (an in-app browser such as Instagram's often reports English).
const CHAT_LOCALE = 'fr';

const ready = new Map<CustomerAddressForm, I18n>();
const loading = new Map<CustomerAddressForm, Promise<I18n>>();

export const loadLiveChatI18n = (form: CustomerAddressForm): Promise<I18n> => {
    const known = loading.get(form);
    if (known) {
        return known;
    }
    const catalog = form === 'tu' ? 'fr-tu' : 'fr';
    const promise = import(`../../../locales/${catalog}.po`).then((module) => {
        const instance = setupI18n({locale: CHAT_LOCALE, messages: {[CHAT_LOCALE]: module.messages}});
        ready.set(form, instance);
        return instance;
    });
    promise.catch(() => loading.delete(form));
    loading.set(form, promise);
    return promise;
};

export const liveChatAddressForm = (organizer?: Organizer | null): CustomerAddressForm => {
    const setting = organizer?.settings?.customer_address_form;
    return setting ? normalizeCustomerAddressForm(setting) : getCustomerAddressForm();
};

/**
 * The French catalog of the chat, in the organizer's tone (tu / vous). Until it is loaded, the page catalog is used.
 */
export const useLiveChatI18n = (organizer?: Organizer | null): I18n => {
    const form = liveChatAddressForm(organizer);
    const [instance, setInstance] = useState<I18n | undefined>(() => ready.get(form));

    useEffect(() => {
        let active = true;
        loadLiveChatI18n(form)
            .then((loaded) => {
                if (active) {
                    setInstance(loaded);
                }
            })
            .catch(() => undefined);
        return () => {
            active = false;
        };
    }, [form]);

    return instance ?? ready.get(form) ?? pageI18n;
};
