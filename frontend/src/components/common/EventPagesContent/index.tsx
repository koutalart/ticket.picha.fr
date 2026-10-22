import {t} from "@lingui/macro";
import {
    IconBrandFacebook,
    IconBrandInstagram,
    IconBrandSpotify,
    IconBrandTiktok,
    IconBrandYoutube,
    IconChevronDown,
    IconMicrophone,
    IconWorld,
} from "@tabler/icons-react";
import {EventArtist, EventArtistLinkType, EventFaqItem, EventPageSection} from "../../../types.ts";
import {RichDescription} from "../RichDescription";
import {artistAnchor, slugify} from "../../../utilites/eventPages.ts";
import classes from "./EventPagesContent.module.scss";

const LINK_ICONS: Record<EventArtistLinkType, typeof IconWorld> = {
    instagram: IconBrandInstagram,
    facebook: IconBrandFacebook,
    tiktok: IconBrandTiktok,
    youtube: IconBrandYoutube,
    spotify: IconBrandSpotify,
    website: IconWorld,
};

/* eslint-disable lingui/no-unlocalized-strings */
const BRAND_LABELS: Record<Exclude<EventArtistLinkType, 'website'>, string> = {
    instagram: 'Instagram',
    facebook: 'Facebook',
    tiktok: 'TikTok',
    youtube: 'YouTube',
    spotify: 'Spotify',
};
/* eslint-enable lingui/no-unlocalized-strings */

const linkLabel = (type: EventArtistLinkType) => type === 'website' ? t`Website` : BRAND_LABELS[type];

const initials = (name: string) => name
    .split(/\s+/)
    .filter((part) => /\p{L}/u.test(part))
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('');

export const ArtistsList = ({artists}: { artists: EventArtist[] }) => (
    <div className={classes.artists}>
        {artists.length > 1 && (
            <nav className={classes.index} aria-label={t`Artists on the line-up`}>
                {artists.map((artist) => (
                    <a key={artistAnchor(artist)} href={`#${artistAnchor(artist)}`} className={classes.indexChip}>
                        {artist.name}
                    </a>
                ))}
            </nav>
        )}
        <div className={classes.artistGrid}>
            {artists.map((artist) => {
                const links = Object.entries(artist.links || {})
                    .filter(([type, url]) => url && type in LINK_ICONS) as [EventArtistLinkType, string][];

                return (
                    <article key={artistAnchor(artist)} id={artistAnchor(artist)} className={classes.artistCard}>
                        <div className={classes.artistPhoto}>
                            {artist.photo_url ? (
                                <img src={artist.photo_url} alt={artist.name} loading="lazy"/>
                            ) : (
                                <span className={classes.artistInitials} aria-hidden="true">{initials(artist.name)}</span>
                            )}
                        </div>
                        <div className={classes.artistBody}>
                            <h2 className={classes.artistName}>
                                <a href={`#${artistAnchor(artist)}`}>{artist.name}</a>
                            </h2>
                            {artist.song && (
                                <p className={classes.artistSong}>
                                    <IconMicrophone size={16} aria-hidden="true"/>
                                    <span>{t`Performs`} <strong>{artist.song}</strong></span>
                                </p>
                            )}
                            {artist.bio && <RichDescription className={classes.richText} html={artist.bio}/>}
                            {links.length > 0 && (
                                <ul className={classes.artistLinks}>
                                    {links.map(([type, url]) => {
                                        const Icon = LINK_ICONS[type];
                                        return (
                                            <li key={type}>
                                                <a href={url} target="_blank" rel="noopener noreferrer"
                                                   aria-label={`${linkLabel(type)} ${artist.name}`} title={linkLabel(type)}>
                                                    <Icon size={18}/>
                                                </a>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </div>
                    </article>
                );
            })}
        </div>
    </div>
);

export const PracticalInfoList = ({sections}: { sections: EventPageSection[] }) => (
    <div className={classes.sections}>
        {sections.length > 2 && (
            <nav className={classes.index} aria-label={t`On this page`}>
                {sections.map((section, index) => (
                    <a key={index} href={`#${slugify(section.title) || index}`} className={classes.indexChip}>
                        {section.title}
                    </a>
                ))}
            </nav>
        )}
        {sections.map((section, index) => (
            <section key={index} id={slugify(section.title) || String(index)} className={classes.infoSection}>
                <h2 className={classes.infoTitle}>{section.title}</h2>
                {section.content && <RichDescription className={classes.richText} html={section.content}/>}
            </section>
        ))}
    </div>
);

export const FaqList = ({items}: { items: EventFaqItem[] }) => (
    <div className={classes.faq}>
        {items.map((item, index) => (
            <details key={index} className={classes.faqItem} open={index === 0}>
                <summary className={classes.faqQuestion}>
                    <h2>{item.question}</h2>
                    <IconChevronDown size={20} className={classes.faqChevron} aria-hidden="true"/>
                </summary>
                <RichDescription className={`${classes.richText} ${classes.faqAnswer}`} html={item.answer}/>
            </details>
        ))}
    </div>
);
