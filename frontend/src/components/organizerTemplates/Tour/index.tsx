import React, {useEffect, useRef, useState} from "react";
import {Link} from "react-router";
import {Helmet} from "react-helmet-async";
import {t} from "@lingui/macro";
import {IconMail, IconPlayerPlayFilled} from "@tabler/icons-react";
import type {OrganizerTemplateProps} from "../index.ts";
import {PoweredByFooter} from "../../common/PoweredByFooter";
import {ContactOrganizerModal} from "../../common/ContactOrganizerModal";
import {CookieConsentBanner} from "../../common/CookieConsentBanner";
import {socialMediaConfig} from "../../../constants/socialMediaConfig";
import {useOrganizerTrackingPixels} from "../../../hooks/useOrganizerTrackingPixels";
import {useCoverVideoSource} from "../../../hooks/useCoverVideoSource.ts";
import {getConfig} from "../../../utilites/config.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {SsrLoaderStyle} from "../../common/SsrLoaderStyle";
import {eventHomepagePath, eventHomepageUrl, organizerHomepagePath} from "../../../utilites/urlHelper.ts";
import {buildEventJsonLd, getVenue} from "../Poster/eventData.ts";
import {dateBlock, eventAccent, eventFlyer, TOUR_FALLBACK_ACCENT, tourCity, tourPrice} from "./tourData.ts";
import classes from "./Tour.module.scss";

const DEFAULT_THEME_ACCENT = '#8b5cf6';
const BACKGROUND = '#0e0c16';

const useReveal = (rootRef: React.RefObject<HTMLDivElement | null>) => {
    useEffect(() => {
        const root = rootRef.current;
        if (!root || typeof IntersectionObserver === 'undefined'
            || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const observer = new IntersectionObserver(entries => entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add(classes.visible);
                observer.unobserve(entry.target);
            }
        }), {threshold: 0.15});

        root.querySelectorAll<HTMLElement>('[data-reveal]').forEach((element, index) => {
            if (element.getBoundingClientRect().top < window.innerHeight) {
                return;
            }
            element.classList.add(classes.reveal);
            element.style.transitionDelay = `${(index % 6) * 60}ms`;
            observer.observe(element);
        });

        return () => observer.disconnect();
    }, [rootRef]);
};

export const TourTemplate = ({organizer, eventsData, siteOrigin}: OrganizerTemplateProps) => {
    const rootRef = useRef<HTMLDivElement>(null);
    const [scrolled, setScrolled] = useState(false);
    const [playing, setPlaying] = useState<string | null>(null);
    const [contactModalOpen, setContactModalOpen] = useState(false);
    const {consentPending, onConsent} = useOrganizerTrackingPixels(organizer.settings?.tracking_pixels);

    useReveal(rootRef);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 40);
        onScroll();
        window.addEventListener('scroll', onScroll, {passive: true});
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    const content = organizer.site_content || {};
    const themeAccent = organizer.settings?.homepage_theme_settings?.accent;
    const accent = themeAccent && themeAccent.toLowerCase() !== DEFAULT_THEME_ACCENT ? themeAccent : TOUR_FALLBACK_ACCENT;
    const logo = organizer.images?.find(image => image.type === 'ORGANIZER_LOGO');
    const cover = organizer.images?.find(image => image.type === 'ORGANIZER_COVER');
    const coverVideo = organizer.images?.find(image => image.type === 'ORGANIZER_COVER_VIDEO')?.url;
    const coverVideoMobile = organizer.images?.find(image => image.type === 'ORGANIZER_COVER_VIDEO_MOBILE')?.url;
    const coverVideoSource = useCoverVideoSource(coverVideo, coverVideoMobile);

    const events = eventsData?.data || [];
    const cities = events.map(tourCity);
    const flyers = events.map(eventFlyer).filter((image): image is NonNullable<typeof image> => Boolean(image));
    const videos = content.videos || [];
    const stats = content.stats || [];
    const partners = content.partners || [];
    const practical = content.practical && content.practical.length > 0 ? content.practical : [
        {title: t`E-ticket`, text: t`Sent by email right after purchase, to show on your phone or printed at the entrance.`},
        {title: t`Secure payment`, text: t`By card. The final price is shown before payment.`},
        {title: t`A question?`, text: t`Use the Contact button on this page, we will get back to you quickly.`},
    ];

    const socialLinks = Object.entries(organizer.settings?.social_media_handles || {})
        .filter(([platform, handle]) => handle && socialMediaConfig[platform as keyof typeof socialMediaConfig])
        .map(([platform, handle]) => ({
            platform,
            url: socialMediaConfig[platform as keyof typeof socialMediaConfig].baseUrl + handle,
            Icon: socialMediaConfig[platform as keyof typeof socialMediaConfig].icon,
        }));
    const youtube = socialLinks.find(link => link.platform === 'youtube');

    const customDomain = organizer.custom_domain;
    const origin = customDomain ? `https://${customDomain}` : (siteOrigin || getConfig('VITE_FRONTEND_URL') as string);
    const canonicalUrl = customDomain ? `${origin}/` : `${origin}${organizerHomepagePath(organizer)}`;
    const title = content.intro_title || organizer.name;
    const description = content.intro_text || content.tagline || t`Tour dates and tickets for ${organizer.name}.`;
    const nextEvent = events[0];
    const finalMonth = nextEvent ? dateBlock(nextEvent).monthYear : null;

    const navItems = [
        {href: '#dates', label: t`Dates`},
        ...(content.about_headline || content.story ? [{href: '#show', label: t`The show`}] : []),
        ...(videos.length > 0 ? [{href: '#clips', label: t`Videos`}] : []),
        {href: '#infos', label: t`Info`},
    ];

    return (
        <>
            <Helmet>
                <title>{title}</title>
                <meta name="description" content={description}/>
                <link rel="canonical" href={canonicalUrl}/>
                <meta property="og:title" content={title}/>
                <meta property="og:description" content={description}/>
                <meta property="og:type" content="website"/>
                <meta property="og:url" content={canonicalUrl}/>
                <meta property="og:site_name" content={organizer.name}/>
                {cover && <meta property="og:image" content={cover.url}/>}
                <meta name="twitter:card" content="summary_large_image"/>
                <meta name="theme-color" content={BACKGROUND}/>
                <link rel="preconnect" href="https://fonts.googleapis.com"/>
                <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin=""/>
                <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Unbounded:wght@700;900&display=swap"/>
                {cover && <link rel="preload" as="image" href={cover.url}/>}
                <script type="application/ld+json">
                    {JSON.stringify({
                        '@context': 'https://schema.org',
                        '@graph': [
                            {'@type': 'Organization', name: organizer.name, url: canonicalUrl, logo: logo?.url},
                            ...events.map(event => buildEventJsonLd(event, eventHomepageUrl(event), organizer.name)),
                        ],
                    })}
                </script>
            </Helmet>
            <SsrLoaderStyle background={BACKGROUND} accent={accent} logoUrl={logo?.url}/>

            <div ref={rootRef} className={classes.page} style={{'--tour-accent': accent} as React.CSSProperties}>
                <header className={`${classes.nav} ${scrolled ? classes.navSolid : ''}`}>
                    <a href="#top" className={classes.brand}>
                        {logo ? <img src={logo.url} alt={organizer.name} className={classes.brandLogo}/> : organizer.name}
                    </a>
                    <nav className={classes.navLinks} aria-label={t`Main menu`}>
                        {navItems.map(item => <a key={item.href} href={item.href}>{item.label}</a>)}
                    </nav>
                    <a href="#dates" className={`${classes.btn} ${classes.btnAccent}`}>{t`Tickets`}</a>
                </header>

                <main id="top">
                    {(coverVideo || cover) && (
                        <section className={classes.hero}>
                            {coverVideoSource ? (
                                <video src={coverVideoSource} poster={cover?.url} aria-label={organizer.name}
                                       autoPlay muted loop playsInline preload="metadata"/>
                            ) : cover ? (
                                <img src={cover.url} alt={organizer.name} fetchPriority="high"/>
                            ) : null}
                        </section>
                    )}

                    <section className={classes.intro}>
                        <div className={`${classes.wrap} ${classes.introInner}`}>
                            <div>
                                {content.tagline && <span className={classes.eyebrow}>{content.tagline}</span>}
                                <h1>{title}</h1>
                                {content.intro_text && <p>{content.intro_text}</p>}
                            </div>
                            <div className={classes.actions}>
                                <a href="#dates" className={`${classes.btn} ${classes.btnAccent}`}>{t`See the dates and book`}</a>
                                {videos.length > 0 && (
                                    <a href="#clips" className={`${classes.btn} ${classes.btnGhost}`}>{t`Watch the videos`}</a>
                                )}
                            </div>
                        </div>
                    </section>

                    {cities.length > 0 && (
                        <div className={classes.marquee} aria-hidden="true">
                            <div className={classes.track}>
                                {[0, 1].map(copy => (
                                    <React.Fragment key={copy}>
                                        {cities.map((city, index) => <span key={`${copy}-${index}`}>{city}</span>)}
                                        {content.upcoming_note && <span className={classes.accentText}>{content.upcoming_note.toUpperCase()}</span>}
                                    </React.Fragment>
                                ))}
                            </div>
                        </div>
                    )}

                    <section id="dates" className={classes.section}>
                        <div className={classes.wrap}>
                            <div className={classes.head} data-reveal="">
                                <div>
                                    <span className={classes.eyebrow}>{t`Dates`}</span>
                                    <h2>{t`Upcoming dates`}</h2>
                                </div>
                                <span className={classes.note}>{t`E-tickets and secure payment`}</span>
                            </div>
                            <div className={classes.dates}>
                                {events.length === 0 && <p className={classes.note}>{t`New dates will be announced soon.`}</p>}
                                {events.map(event => {
                                    const block = dateBlock(event);
                                    const venue = getVenue(event);
                                    const price = tourPrice(event);
                                    const color = eventAccent(event, accent);
                                    const place = [venue.name, venue.city].filter(Boolean).join(', ');
                                    return (
                                        <Link key={event.id} to={eventHomepagePath(event)} className={classes.date} data-reveal="">
                                            <div className={classes.day}>
                                                <small>{block.weekday}</small>
                                                <b>{block.day}</b>
                                                <span>{block.month}</span>
                                            </div>
                                            <div>
                                                <div className={classes.city} style={{color}}>{tourCity(event)}</div>
                                                <div className={classes.place}>
                                                    {place ? t`${place}, from ${block.time}` : t`From ${block.time}`}
                                                </div>
                                            </div>
                                            <div className={classes.side}>
                                                {price.lowest !== null && !price.soldOut && (
                                                    <div className={classes.price}>
                                                        {price.lowest === 0 ? <b>{t`Free`}</b> : <>
                                                            {t`from`}
                                                            <b>{formatCurrency(price.lowest, event.currency)}</b>
                                                        </>}
                                                    </div>
                                                )}
                                                <span className={`${classes.ticket} ${price.soldOut ? classes.ticketSoldOut : ''}`}
                                                      style={price.soldOut ? undefined : {background: color}}>
                                                    {price.soldOut ? t`Sold out` : t`Tickets`}
                                                </span>
                                            </div>
                                        </Link>
                                    );
                                })}
                                {(content.upcoming_note || content.notify_url) && (
                                    <div className={`${classes.date} ${classes.soon}`} data-reveal="">
                                        <div className={classes.day}><b>+</b></div>
                                        <div>
                                            <div className={classes.city}>{t`MORE CITIES`}</div>
                                            {content.upcoming_note && <div className={classes.place}>{content.upcoming_note}</div>}
                                        </div>
                                        <div className={classes.side}>
                                            {content.notify_url ? (
                                                <a href={content.notify_url} target="_blank" rel="noopener noreferrer"
                                                   className={`${classes.ticket} ${classes.ticketGhost}`}>{t`Get notified`}</a>
                                            ) : (
                                                <button type="button" onClick={() => setContactModalOpen(true)}
                                                        className={`${classes.ticket} ${classes.ticketGhost}`}>{t`Get notified`}</button>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </section>

                    {(content.about_headline || content.story) && (
                        <section id="show" className={classes.section}>
                            <div className={`${classes.wrap} ${classes.show}`}>
                                <div>
                                    <span className={classes.eyebrow}>{t`The show`}</span>
                                    {content.about_headline && <h2 data-reveal="">{content.about_headline}</h2>}
                                    {content.story && (
                                        <div className={classes.story} data-reveal=""
                                             dangerouslySetInnerHTML={{__html: content.story}}/>
                                    )}
                                    {stats.length > 0 && (
                                        <div className={classes.stats}>
                                            {stats.map((stat, index) => (
                                                <div key={index} className={classes.stat} data-reveal="">
                                                    <b>{stat.value}</b>
                                                    <span>{stat.label}</span>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                                {flyers.length > 0 && (
                                    <div className={classes.mosaic}>
                                        {[flyers[0], flyers[flyers.length - 1]].slice(0, flyers.length > 1 ? 2 : 1).map(flyer => (
                                            <img key={flyer.id} src={flyer.url} alt="" loading="lazy" decoding="async" data-reveal=""/>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </section>
                    )}

                    {videos.length > 0 && (
                        <section id="clips" className={classes.section}>
                            <div className={classes.wrap}>
                                <div className={classes.head} data-reveal="">
                                    <div>
                                        <span className={classes.eyebrow}>{t`Videos`}</span>
                                        <h2>{t`Watch the videos`}</h2>
                                    </div>
                                    {youtube && (
                                        <a className={classes.note} href={youtube.url} target="_blank" rel="noopener noreferrer">
                                            {t`YouTube channel`}
                                        </a>
                                    )}
                                </div>
                                <div className={classes.clips}>
                                    {videos.map(video => (
                                        <div key={video.youtube_id} className={classes.clip} data-reveal="">
                                            {playing === video.youtube_id ? (
                                                <iframe
                                                    className={classes.thumb}
                                                    src={`https://www.youtube-nocookie.com/embed/${video.youtube_id}?autoplay=1&rel=0`}
                                                    title={video.title}
                                                    allow="autoplay; encrypted-media; picture-in-picture"
                                                    allowFullScreen
                                                />
                                            ) : (
                                                <button
                                                    type="button"
                                                    className={classes.thumb}
                                                    style={{backgroundImage: `url(https://i.ytimg.com/vi/${video.youtube_id}/hqdefault.jpg)`}}
                                                    onClick={() => setPlaying(video.youtube_id)}
                                                    aria-label={t`Play ${video.title}`}
                                                >
                                                    <IconPlayerPlayFilled size={30}/>
                                                </button>
                                            )}
                                            <div className={classes.clipText}>
                                                {video.title}
                                                {video.subtitle && <small>{video.subtitle}</small>}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </section>
                    )}

                    {flyers.length > 0 && (
                        <section className={classes.section}>
                            <div className={classes.wrap}>
                                <div className={classes.head} data-reveal="">
                                    <div>
                                        <span className={classes.eyebrow}>{t`Posters`}</span>
                                        <h2>{t`One poster per date`}</h2>
                                    </div>
                                </div>
                                <div className={classes.posters}>
                                    {flyers.map(flyer => (
                                        <a key={flyer.id} href={flyer.url} target="_blank" rel="noopener noreferrer" data-reveal="">
                                            <img src={flyer.url} alt="" loading="lazy" decoding="async"/>
                                        </a>
                                    ))}
                                </div>
                            </div>
                        </section>
                    )}

                    <section id="infos" className={classes.section}>
                        <div className={classes.wrap}>
                            <div className={classes.head} data-reveal="">
                                <div>
                                    <span className={classes.eyebrow}>{t`Practical info`}</span>
                                    <h2>{t`All you need to know before the night`}</h2>
                                </div>
                            </div>
                            <div className={classes.infos}>
                                {practical.map((item, index) => (
                                    <div key={index} className={classes.info} data-reveal="">
                                        <h3>{item.title}</h3>
                                        {item.text && <p>{item.text}</p>}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>

                    {partners.length > 0 && (
                        <section className={classes.section}>
                            <div className={classes.wrap}>
                                <div className={classes.head} data-reveal="">
                                    <div>
                                        <span className={classes.eyebrow}>{t`Partners`}</span>
                                        <h2>{t`They make the tour happen`}</h2>
                                    </div>
                                </div>
                                <div className={classes.partners}>
                                    {partners.map((partner, index) => {
                                        const inner = partner.logo_url
                                            ? <img src={partner.logo_url} alt={partner.name} loading="lazy" decoding="async"/>
                                            : partner.name;
                                        return partner.url ? (
                                            <a key={index} href={partner.url} target="_blank" rel="noopener noreferrer" data-reveal="">{inner}</a>
                                        ) : (
                                            <span key={index} data-reveal="">{inner}</span>
                                        );
                                    })}
                                </div>
                            </div>
                        </section>
                    )}

                    <section className={`${classes.section} ${classes.final}`}>
                        <div className={classes.wrap}>
                            {finalMonth && <span className={classes.eyebrow}>{finalMonth}</span>}
                            <h2 data-reveal="">{t`Get your ticket for the tour`}</h2>
                            {content.upcoming_note && <p className={classes.note}>{content.upcoming_note}</p>}
                            <a href="#dates" className={`${classes.btn} ${classes.btnAccent}`}>{t`Choose my city`}</a>
                        </div>
                    </section>
                </main>

                <footer className={classes.footer}>
                    <div className={`${classes.wrap} ${classes.footerInner}`}>
                        <span>{organizer.name}</span>
                        <div className={classes.socials}>
                            {socialLinks.map(({platform, url, Icon}) => (
                                <a key={platform} href={url} target="_blank" rel="noopener noreferrer" aria-label={platform}>
                                    <Icon size={20}/>
                                </a>
                            ))}
                            <button type="button" onClick={() => setContactModalOpen(true)}>
                                <IconMail size={16}/> {t`Contact`}
                            </button>
                        </div>
                    </div>
                    <PoweredByFooter className={classes.poweredBy}/>
                </footer>
            </div>

            <ContactOrganizerModal opened={contactModalOpen} onClose={() => setContactModalOpen(false)} organizer={organizer}/>
            {consentPending && <CookieConsentBanner onConsent={onConsent}/>}
        </>
    );
};

export default TourTemplate;
