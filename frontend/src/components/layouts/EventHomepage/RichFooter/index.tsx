import {FormEvent, useId, useState} from "react";
import {t} from "@lingui/macro";
import {IconArrowUp, IconExternalLink, IconWorld} from "@tabler/icons-react";
import classes from "./RichFooter.module.scss";
import {Event, HomepageFooterSettings, Organizer} from "../../../../types.ts";
import {socialMediaConfig} from "../../../../constants/socialMediaConfig";
import {getAppName, getPrivacyPolicyUrl, getTermsOfUseUrl} from "../../../../utilites/branding.ts";
import {getConfig} from "../../../../utilites/config.ts";
import {toParticipantUrl} from "../../../../utilites/urlHelper.ts";
import {useSubscribeToOrganizerNewsletter} from "../../../../mutations/useSubscribeToOrganizerNewsletter.ts";
import {showError} from "../../../../utilites/notifications.tsx";
import {EMAIL_PATTERN} from "../../../../utilites/embeddedRegistration.ts";
import {LiveChatFooterLink} from "../../../common/LiveChatWindow/LiveChatFooterLink.tsx";

interface SocialLink {
    platform: string;
    handle: string;
    config: typeof socialMediaConfig[keyof typeof socialMediaConfig];
}

interface RichFooterProps {
    event: Event;
    organizer: Organizer;
    organizerLogo?: string;
    socialLinks: SocialLink[];
    websiteUrl?: string;
    onRegister: () => void;
    onContact: () => void;
    onManageCookies: () => void;
}

const isExternal = (url: string) => /^https?:\/\//i.test(url);

const linkTargetProps = (url: string) => isExternal(url)
    ? {target: "_blank", rel: "noopener noreferrer"}
    : {};

const platformLabel = (platform: string) => platform.charAt(0).toUpperCase() + platform.slice(1);

export const RichFooter = ({
                               event,
                               organizer,
                               organizerLogo,
                               socialLinks,
                               websiteUrl,
                               onRegister,
                               onContact,
                               onManageCookies,
                           }: RichFooterProps) => {
    const settings: HomepageFooterSettings = event.settings?.homepage_footer_settings || {};
    const newsletterEnabled = settings.newsletter_enabled !== false;
    const privacyUrl = settings.privacy_policy_url || getPrivacyPolicyUrl();
    const termsUrl = settings.terms_url || getTermsOfUseUrl();
    const eventLinks = (settings.event_links || [])
        .filter(link => link?.label && link?.url)
        .map(link => ({...link, url: toParticipantUrl(link.url, organizer)}));
    const relatedLinks = (settings.related_links || [])
        .filter(link => link?.title && link?.url)
        .slice(0, 3)
        .map(link => ({...link, url: toParticipantUrl(link.url, organizer)}));
    const brandUrl = getConfig("VITE_BRAND_URL", "https://ticket.picha.fr") as string;

    const emailId = useId();
    const consentId = useId();
    const [email, setEmail] = useState("");
    const [emailError, setEmailError] = useState<string | null>(null);
    const [subscribed, setSubscribed] = useState(false);
    const subscribeMutation = useSubscribeToOrganizerNewsletter();

    const consentText = t`By subscribing, you agree to receive news about the events of ${organizer.name} by e-mail. Your address is only used for this purpose and you can unsubscribe at any time.`;

    const handleSubscribe = (formEvent: FormEvent<HTMLFormElement>) => {
        formEvent.preventDefault();
        const value = email.trim();

        if (!EMAIL_PATTERN.test(value)) {
            setEmailError(t`Please enter a valid e-mail address`);
            return;
        }

        setEmailError(null);
        subscribeMutation.mutate({
            eventId: event.id!,
            email: value,
            consentText: `${consentText} ${privacyUrl}`,
        }, {
            onSuccess: () => {
                setSubscribed(true);
                setEmail("");
            },
            onError: (error: any) => {
                const fieldError = error?.response?.data?.errors?.email;
                if (fieldError) {
                    setEmailError(Array.isArray(fieldError) ? fieldError[0] : String(fieldError));
                    return;
                }
                showError(error?.response?.status === 429
                    ? t`Too many attempts. Please try again in a minute.`
                    : t`Something went wrong. Please try again.`);
            },
        });
    };

    const scrollToTop = () => {
        if (typeof window === "undefined") {
            return;
        }
        const reduceMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
        window.scrollTo({top: 0, behavior: reduceMotion ? "auto" : "smooth"});
    };

    return (
        <footer className={classes.footer} aria-label={t`Page footer`}>
            {newsletterEnabled && (
                <section className={classes.newsletter} aria-labelledby={`${emailId}-title`}>
                    <h2 id={`${emailId}-title`} className={classes.newsletterTitle}>
                        {settings.newsletter_title || t`Stay informed`}
                    </h2>
                    {subscribed ? (
                        <p className={classes.newsletterSuccess} role="status">
                            {t`Thank you, your subscription has been recorded.`}
                        </p>
                    ) : (
                        <form className={classes.newsletterForm} onSubmit={handleSubscribe} noValidate>
                            <label htmlFor={emailId} className={classes.visuallyHidden}>
                                {t`Your e-mail address`}
                            </label>
                            <div className={classes.newsletterField}>
                                <input
                                    id={emailId}
                                    type="email"
                                    name="email"
                                    autoComplete="email"
                                    inputMode="email"
                                    required
                                    placeholder={t`Your e-mail address`}
                                    value={email}
                                    onChange={(changeEvent) => setEmail(changeEvent.currentTarget.value)}
                                    aria-invalid={!!emailError}
                                    aria-describedby={`${consentId}${emailError ? ` ${emailId}-error` : ""}`}
                                    className={classes.newsletterInput}
                                />
                                <button
                                    type="submit"
                                    className={classes.newsletterButton}
                                    disabled={subscribeMutation.isPending}
                                >
                                    {t`Subscribe`}
                                </button>
                            </div>
                            {emailError && (
                                <p id={`${emailId}-error`} className={classes.newsletterError} role="alert">
                                    {emailError}
                                </p>
                            )}
                        </form>
                    )}
                    <p id={consentId} className={classes.newsletterConsent}>
                        {consentText}{" "}
                        <a href={privacyUrl} {...linkTargetProps(privacyUrl)}>{t`Privacy policy`}</a>
                    </p>
                </section>
            )}

            {(socialLinks.length > 0 || websiteUrl) && (
                <div className={classes.socialRow}>
                    <span className={classes.socialRule} aria-hidden="true"/>
                    <ul className={classes.socialList} aria-label={t`Follow ${organizer.name}`}>
                        {socialLinks.map(({platform, handle, config}) => {
                            const IconComponent = config.icon;
                            return (
                                <li key={platform}>
                                    <a
                                        href={config.baseUrl + handle}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className={classes.socialLink}
                                        aria-label={platformLabel(platform)}
                                    >
                                        <IconComponent size={20} aria-hidden="true"/>
                                    </a>
                                </li>
                            );
                        })}
                        {websiteUrl && (
                            <li>
                                <a
                                    href={websiteUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={classes.socialLink}
                                    aria-label={t`Website`}
                                >
                                    <IconWorld size={20} aria-hidden="true"/>
                                </a>
                            </li>
                        )}
                    </ul>
                    <span className={classes.socialRule} aria-hidden="true"/>
                </div>
            )}

            <div className={classes.columns}>
                <div className={classes.brandColumn}>
                    {organizerLogo ? (
                        <img src={organizerLogo} alt={organizer.name} className={classes.logo} loading="lazy"/>
                    ) : (
                        <span className={classes.brandName}>{organizer.name}</span>
                    )}
                </div>

                <nav className={classes.column} aria-label={t`The event`}>
                    <h2 className={classes.columnTitle}>{t`The event`}</h2>
                    <ul className={classes.linkList}>
                        <li>
                            <a
                                href="#tickets"
                                className={classes.link}
                                onClick={(clickEvent) => {
                                    clickEvent.preventDefault();
                                    onRegister();
                                }}
                            >
                                {t`Register`}
                            </a>
                        </li>
                        {eventLinks.map((link, index) => (
                            <li key={`${link.url}-${index}`}>
                                <a href={link.url} className={classes.link} {...linkTargetProps(link.url)}>
                                    {link.label}
                                </a>
                            </li>
                        ))}
                    </ul>
                </nav>

                {relatedLinks.length > 0 && (
                    <nav className={`${classes.column} ${classes.relatedColumn}`} aria-label={t`See also`}>
                        <h2 className={classes.columnTitle}>{t`See also`}</h2>
                        <ul className={classes.cardList}>
                            {relatedLinks.map((link, index) => (
                                <li key={`${link.url}-${index}`}>
                                    <a href={link.url} className={classes.card} {...linkTargetProps(link.url)}>
                                        {link.image_url && (
                                            <img src={link.image_url} alt="" className={classes.cardImage} loading="lazy"/>
                                        )}
                                        <span className={classes.cardTitle}>{link.title}</span>
                                        {isExternal(link.url) && (
                                            <IconExternalLink size={16} className={classes.cardIcon} aria-hidden="true"/>
                                        )}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </nav>
                )}

                <nav className={classes.column} aria-label={t`Legal information`}>
                    <h2 className={classes.columnTitle}>{t`Legal information`}</h2>
                    <ul className={classes.linkList}>
                        <li>
                            <a href={privacyUrl} className={classes.link} {...linkTargetProps(privacyUrl)}>
                                {t`Privacy policy`}
                            </a>
                        </li>
                        <li>
                            <a href={termsUrl} className={classes.link} {...linkTargetProps(termsUrl)}>
                                {t`Terms of use and legal notice`}
                            </a>
                        </li>
                        <li>
                            <button type="button" className={classes.linkButton} onClick={onManageCookies}>
                                {t`Manage my trackers`}
                            </button>
                        </li>
                        {settings.accessibility_url && (
                            <li>
                                <a href={settings.accessibility_url} className={classes.link}
                                   {...linkTargetProps(settings.accessibility_url)}>
                                    {t`Accessibility`}
                                </a>
                            </li>
                        )}
                        <li>
                            <button type="button" className={classes.linkButton} onClick={onContact}>
                                {t`Contact us`}
                            </button>
                        </li>
                        <LiveChatFooterLink organizer={organizer} eventId={event.id} className={classes.linkButton} asListItem/>
                    </ul>
                </nav>
            </div>

            <div className={classes.bottomBar}>
                <p className={classes.poweredBy}>
                    {t`Powered by`}{" "}
                    <a href={brandUrl} target="_blank" rel="noopener noreferrer">{getAppName()}</a>
                </p>
                <button type="button" className={classes.backToTop} onClick={scrollToTop}>
                    <IconArrowUp size={16} aria-hidden="true"/>
                    {t`Back to top`}
                </button>
            </div>
        </footer>
    );
};
