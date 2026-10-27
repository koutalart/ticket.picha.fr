import {CSSProperties, useEffect, useId, useRef, useState} from 'react';
import {t} from '@lingui/macro';
import {IconCookie, IconX} from '@tabler/icons-react';
import {getConfig} from '../../../utilites/config';
import {ConsentBannerTheme} from '../../../utilites/consentBannerTheme.ts';
import {getPublicPalette, readableAccent} from '../PublicOverlayTheme/palette.ts';
import {getContrastColor} from '../../../utilites/themeUtils.ts';
import classes from './CookieConsentBanner.module.scss';

export type CookieConsentView = 'main' | 'preferences';

export interface CookieConsentSelection {
    audience: boolean;
    marketing: boolean;
}

interface CookieConsentBannerProps {
    view: CookieConsentView;
    theme: ConsentBannerTheme | null;
    initialSelection?: CookieConsentSelection | null;
    onSave: (selection: CookieConsentSelection) => void;
    onCustomize: () => void;
}

const DEFAULT_ACCENT = '#40296C';

const cookiePolicyUrl = (): string => {
    const platformUrl = (getConfig('VITE_FRONTEND_URL') as string | undefined)?.replace(/\/$/, '') ?? '';
    return `${platformUrl}/legal/cookies`;
};

const themeStyle = (theme: ConsentBannerTheme | null): CSSProperties => {
    const mode = theme?.mode ?? 'light';
    const palette = getPublicPalette(mode);
    const accent = theme?.accentColor || (getConfig('VITE_APP_PRIMARY_COLOR', DEFAULT_ACCENT) as string);

    return {
        '--cb-surface': palette.surface,
        '--cb-text': palette.textPrimary,
        '--cb-muted': palette.textSecondary,
        '--cb-border': palette.border,
        '--cb-accent': accent,
        '--cb-accent-text': readableAccent(accent, palette.surface),
        '--cb-accent-contrast': getContrastColor(accent),
        '--cb-soft': mode === 'dark' ? 'rgba(255, 255, 255, 0.06)' : 'rgba(0, 0, 0, 0.04)',
        ...(theme?.fontFamily ? {fontFamily: theme.fontFamily} : {}),
    } as CSSProperties;
};

interface CategoryProps {
    title: string;
    description: string;
    checked: boolean;
    locked?: boolean;
    onChange?: (checked: boolean) => void;
}

const Category = ({title, description, checked, locked, onChange}: CategoryProps) => {
    const id = useId();

    return (
        <div className={classes.category}>
            <div className={classes.categoryHeader}>
                <label htmlFor={id} className={classes.categoryTitle}>{title}</label>
                {locked ? (
                    <span className={classes.alwaysOn}>{t`Always active`}</span>
                ) : (
                    <input
                        id={id}
                        type="checkbox"
                        role="switch"
                        className={classes.switch}
                        checked={checked}
                        onChange={(event) => onChange?.(event.currentTarget.checked)}
                        aria-describedby={`${id}-description`}
                    />
                )}
            </div>
            <p id={`${id}-description`} className={classes.categoryDescription}>{description}</p>
        </div>
    );
};

/**
 * Consent banner shown on public pages: refusing is as easy as accepting, and the advertising
 * category is never pre-selected.
 */
export const CookieConsentBanner = ({view, theme, initialSelection, onSave, onCustomize}: CookieConsentBannerProps) => {
    const titleId = useId();
    const firstButtonRef = useRef<HTMLButtonElement>(null);
    const barRef = useRef<HTMLDivElement>(null);
    const [audience, setAudience] = useState(initialSelection?.audience ?? true);
    const [marketing, setMarketing] = useState(initialSelection?.marketing ?? false);

    useEffect(() => {
        if (view === 'preferences') {
            firstButtonRef.current?.focus();
        }
    }, [view]);

    useEffect(() => {
        const bar = barRef.current;
        if (view !== 'main' || !bar || typeof ResizeObserver === 'undefined') {
            return;
        }
        const root = document.documentElement;
        const publish = () => {
            root.style.setProperty('--picha-cookie-bar-height', `${Math.ceil(bar.getBoundingClientRect().height)}px`);
            window.dispatchEvent(new Event('picha:bottom-bar-change'));
        };
        const observer = new ResizeObserver(publish);
        observer.observe(bar);
        publish();
        return () => {
            observer.disconnect();
            root.style.removeProperty('--picha-cookie-bar-height');
            window.dispatchEvent(new Event('picha:bottom-bar-change'));
        };
    }, [view]);

    const refuse = () => onSave({audience, marketing: false});
    const accept = () => onSave({audience: true, marketing: true});

    if (view === 'main') {
        return (
            <div
                ref={barRef}
                className={classes.bar}
                style={themeStyle(theme)}
                role="region"
                aria-label={t`Cookies and trackers`}
                data-view={view}
            >
                <p className={classes.barText}>
                    {t`Cookies to measure our ads.`}{' '}
                    <button type="button" className={classes.barLink} onClick={onCustomize}>
                        {t`Info`}
                    </button>
                </p>
                <div className={classes.barActions}>
                    <button type="button" className={classes.barButton} onClick={refuse}>
                        {t`Refuse`}
                    </button>
                    <button type="button" className={classes.barButton} onClick={accept}>
                        {t`Accept`}
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div
            className={classes.banner}
            style={themeStyle(theme)}
            role="dialog"
            aria-modal="false"
            aria-labelledby={titleId}
            data-view={view}
        >
            <button
                type="button"
                className={classes.closeButton}
                onClick={refuse}
                aria-label={t`Continue without accepting`}
                title={t`Continue without accepting`}
            >
                <IconX size={16}/>
            </button>

            <div className={classes.header}>
                <span className={classes.iconWrapper}><IconCookie size={20}/></span>
                <h2 id={titleId} className={classes.title}>{t`Cookies and trackers`}</h2>
            </div>

            <div className={classes.categories}>
                <Category
                    title={t`Strictly necessary`}
                    description={t`Session, cart, language and the memory of your choice. Required for the site to work.`}
                    checked
                    locked
                />
                <Category
                    title={t`Exempt audience measurement`}
                    description={t`Campaign source (utm parameters), referring site and landing page, kept 90 days on this site to count visits and sales per channel. Exempt from consent, you can object to it.`}
                    checked={audience}
                    onChange={setAudience}
                />
                <Category
                    title={t`Advertising and marketing`}
                    description={t`Advertising identifiers (Meta _fbp and _fbc cookies, Google _gcl_aw cookie, ad click identifiers) kept 90 days to measure the results of ads. Only with your consent.`}
                    checked={marketing}
                    onChange={setMarketing}
                />
                <a href={cookiePolicyUrl()} target="_blank" rel="noopener noreferrer" className={classes.link}>
                    {t`Cookie policy`}
                </a>
            </div>

            <div className={classes.actions}>
                <button ref={firstButtonRef} type="button" className={classes.choiceButton}
                        onClick={() => onSave({audience, marketing: false})}>
                    {t`Refuse all`}
                </button>
                <button type="button" className={classes.secondaryButton}
                        onClick={() => onSave({audience, marketing})}>
                    {t`Save my choices`}
                </button>
                <button type="button" className={classes.choiceButton} onClick={accept}>
                    {t`Accept all`}
                </button>
            </div>
        </div>
    );
};
