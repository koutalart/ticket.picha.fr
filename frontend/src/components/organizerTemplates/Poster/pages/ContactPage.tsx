import {t} from "@lingui/macro";
import {IconArrowRight, IconDownload, IconMail, IconMapPin, IconNews, IconPhone} from "@tabler/icons-react";
import {socialMediaConfig} from "../../../../constants/socialMediaConfig";
import {formatAddress} from "../../../../utilites/addressUtilities.ts";
import {areaSuffix, PageIntro, PosterPageProps} from "../shared.tsx";
import classes from "../Poster.module.scss";

export const ContactPage = ({organizer, openContact}: PosterPageProps) => {
    const content = organizer.site_content || {};
    const location = organizer.settings?.location_details;
    const address = location && (location.address_line_1 || location.city) ? formatAddress(location) : content.area;
    const hasPress = Boolean(content.press_text || content.press_email || content.press_phone || content.press_kit_url);

    const socialLinks = Object.entries(organizer.settings?.social_media_handles || {})
        .filter(([platform, handle]) => handle && socialMediaConfig[platform as keyof typeof socialMediaConfig])
        .map(([platform, handle]) => {
            const config = socialMediaConfig[platform as keyof typeof socialMediaConfig];
            return {platform, handle: handle as string, url: config.baseUrl + handle, Icon: config.icon};
        });

    return (
        <>
            <PageIntro
                heading={t`Contact ${organizer.name}${areaSuffix(organizer)}`}
                title={t`Let's talk.`}
                lead={t`A question about an event, a booking, a project? The ${organizer.name} team answers you.`}
            />

            <section className={classes.section} aria-label={t`Contact details`}>
                <div className={classes.contactGrid}>
                    <article className={classes.contactCard}>
                        <IconMail size={28}/>
                        <h2>{t`Write to us`}</h2>
                        <p>{t`Send us a message, we will reply by e-mail as soon as possible.`}</p>
                        <button className={classes.primaryCta} onClick={openContact}>
                            {t`Send a message`} <IconArrowRight size={18}/>
                        </button>
                    </article>

                    {hasPress && (
                        <article className={classes.contactCard}>
                            <IconNews size={28}/>
                            <h2>{t`Press & media`}</h2>
                            {content.press_text && <p>{content.press_text}</p>}
                            <ul className={classes.contactList}>
                                {content.press_email && (
                                    <li><IconMail size={18}/><a href={`mailto:${content.press_email}`}>{content.press_email}</a></li>
                                )}
                                {content.press_phone && (
                                    <li><IconPhone size={18}/><a href={`tel:${content.press_phone.replace(/\s/g, '')}`}>{content.press_phone}</a></li>
                                )}
                            </ul>
                            {content.press_kit_url && (
                                <a href={content.press_kit_url} target="_blank" rel="noopener noreferrer" className={classes.tierCta}>
                                    {t`Download the press kit`} <IconDownload size={16}/>
                                </a>
                            )}
                        </article>
                    )}

                    {(address || socialLinks.length > 0) && (
                        <article className={classes.contactCard}>
                            <IconMapPin size={28}/>
                            <h2>{t`Find us`}</h2>
                            {address && <p>{address}</p>}
                            {socialLinks.length > 0 && (
                                <ul className={classes.contactList}>
                                    {socialLinks.map(({platform, handle, url, Icon}) => (
                                        <li key={platform}>
                                            <Icon size={18}/>
                                            <a href={url} target="_blank" rel="noopener noreferrer">{handle}</a>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </article>
                    )}
                </div>
            </section>
        </>
    );
};
