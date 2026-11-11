import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import timezone from "dayjs/plugin/timezone";
import "dayjs/locale/fr";

dayjs.extend(utc);
dayjs.extend(timezone);

const VARIABLE_PATTERN = /\{\s*(pr[eé]nom|[eé]v[eé]nement|date|heure|lien)\s*\}/giu;
const LABELLED_LINK_PATTERN = /[^.!?\n:]*:[ \t]*\{\s*lien\s*\}/giu;
const LINK_AFTER_LABEL_PATTERN = /:([ \t]*)\{\s*lien\s*\}/giu;
const LINK_PATTERN = /\{\s*lien\s*\}/giu;
const LINK_BELOW = 'lien ci-dessous';
export const SAMPLE_SHORT_LINK_PATH = '/a1b2c3d';

interface SmsTemplateValues {
    firstName: string;
    eventTitle: string;
    startDate?: string | null;
    timezone?: string | null;
    personalLink: string | null;
}

const variableKey = (name: string) => name.toLowerCase().replace(/é/g, 'e');

/**
 * Same rendering as the backend (SmsReminderMessageRenderer): dates in French in the event timezone.
 * The link never stays in the text: it goes alone in a second SMS, its label then says "lien ci-dessous".
 * Without a link, the label is removed too.
 */
export const renderSmsTemplate = (template: string, values: SmsTemplateValues): string => {
    let start = null;
    if (values.startDate) {
        try {
            start = dayjs.utc(values.startDate).tz(values.timezone || 'UTC').locale('fr');
        } catch {
            start = null;
        }
    }

    const replacements: Record<string, string> = {
        prenom: values.firstName.trim(),
        evenement: values.eventTitle.trim(),
        date: start ? start.format('dddd D MMMM') : '',
        heure: start ? start.format('HH[h]mm') : '',
    };

    const hasLink = (values.personalLink || '').trim() !== '';
    const source = (hasLink
        ? template.replace(LINK_AFTER_LABEL_PATTERN, `:$1${LINK_BELOW}`)
        : template.replace(LABELLED_LINK_PATTERN, '')
    ).replace(LINK_PATTERN, '');
    const rendered = source.replace(VARIABLE_PATTERN, (match, name: string) => replacements[variableKey(name)] ?? match);

    const lines = rendered
        .replace(/\r\n/g, '\n')
        .split('\n')
        .map(line => line
            .replace(/[ \t]{2,}/g, ' ')
            .replace(/[ \t]+([,.])/g, '$1')
            .replace(/^([,.]\s*)/, '')
            .trim());

    return lines.join('\n').replace(/\n{3,}/g, '\n\n').trim();
};

/**
 * The second SMS: the link alone, when the template uses {lien} and there is one.
 */
export const smsLinkMessage = (template: string, link: string | null): string | null => {
    const value = (link || '').trim();

    return value !== '' && /\{\s*lien\s*\}/iu.test(template) ? value : null;
};
