import {Link} from "react-router";
import {t} from "@lingui/macro";
import {
    IconArrowRight,
    IconCalendarEvent,
    IconChevronDown,
    IconClock,
    IconLock,
    IconMail,
    IconMapPin,
    IconTicket,
} from "@tabler/icons-react";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {eventHomepagePath} from "../../../../utilites/urlHelper.ts";
import {Countdown} from "../Countdown.tsx";
import {getCoverUrl, getVenue} from "../eventData.ts";
import {getFaq} from "../faq.ts";
import {longDate, PartnerLogos, PosterEventCard, PosterPageProps, SectionHead, StatsBand, stripHtml, time} from "../shared.tsx";
import classes from "../Poster.module.scss";

export const HomePage = ({organizer, eventsData, featured, tiers, fromPrice, sitePath, openContact}: PosterPageProps) => {
    const content = organizer.site_content || {};
    const events = eventsData?.data || [];
    const otherEvents = featured ? events.slice(1) : events;
    const featuredCover = featured ? getCoverUrl(featured) : undefined;
    const venue = featured ? getVenue(featured) : undefined;
    const bookingPath = featured ? eventHomepagePath(featured) : '';
    const allSoldOut = tiers.length > 0 && tiers.every(tier => tier.soldOut);
    const bookingLabel = allSoldOut ? t`Sold out — join the waitlist` : t`Book my tickets`;
    const hasUniverse = Boolean(content.tagline || content.story || content.vision || content.stats?.length);

    return (
        <>
            <section className={classes.hero} aria-labelledby="hero-title">
                {featuredCover && (
                    <div className={classes.heroBackdrop} style={{backgroundImage: `url(${featuredCover})`}} aria-hidden="true"/>
                )}
                <div className={classes.heroGlow} aria-hidden="true"/>

                {featured ? (
                    <div className={classes.heroInner}>
                        <div className={classes.heroText}>
                            <h1 id="hero-title" className={classes.heroHeading}>
                                <span className={classes.eyebrow}>{t`${organizer.name} presents`}</span>
                                <span className={classes.heroTitle}>{featured.title}</span>
                            </h1>
                            <p className={classes.heroMeta}>
                                <span className={classes.capitalize}>{longDate(featured)}</span>
                                <span>{time(featured.start_date, featured)}</span>
                                {venue?.city && <span>{venue.city}</span>}
                            </p>

                            <Countdown startDate={featured.start_date}/>

                            <div className={classes.heroActions}>
                                <Link to={bookingPath} className={classes.primaryCta}>
                                    {bookingLabel}
                                    {fromPrice && !allSoldOut && <span className={classes.ctaPrice}>{fromPrice}</span>}
                                </Link>
                                {tiers.length > 0 && (
                                    <a href="#tickets" className={classes.secondaryCta}>
                                        {t`See prices`} <IconChevronDown size={18}/>
                                    </a>
                                )}
                            </div>

                            <ul className={classes.heroReassurance}>
                                <li><IconLock size={16}/>{t`Secure payment`}</li>
                                <li><IconMail size={16}/>{t`Instant e-ticket`}</li>
                            </ul>
                        </div>

                        {featuredCover && (
                            <Link to={bookingPath} className={classes.heroPoster} aria-label={t`Book ${featured.title}`}>
                                <img src={featuredCover} alt={t`Poster of ${featured.title}`}/>
                            </Link>
                        )}
                    </div>
                ) : (
                    <div className={classes.heroInner}>
                        <div className={classes.heroText}>
                            <h1 id="hero-title" className={classes.heroHeading}>
                                <span className={classes.eyebrow}>{t`Official website`}</span>
                                <span className={classes.heroTitle}>{organizer.name}</span>
                            </h1>
                            <p className={classes.heroLead}>
                                {content.tagline || t`New dates are coming soon. Follow us so you don't miss the on-sale date.`}
                            </p>
                            <div className={classes.heroActions}>
                                <Link to={sitePath('evenements')} className={classes.primaryCta}>{t`See the events`}</Link>
                                <Link to={sitePath('a-propos')} className={classes.secondaryCta}>
                                    {t`Discover our universe`} <IconArrowRight size={18}/>
                                </Link>
                            </div>
                        </div>
                    </div>
                )}
            </section>

            {featured && tiers.length > 0 && (
                <section id="tickets" className={classes.section} aria-labelledby="tickets-title">
                    <SectionHead id="tickets-title" eyebrow={t`Tickets`} title={t`Choose your ticket.`}/>
                    <div className={classes.tiers}>
                        {tiers.map(tier => (
                            <article key={tier.id} className={`${classes.tier} ${tier.highlight ? classes.tierHighlighted : ''}`}>
                                {tier.highlight && <span className={classes.tierBadge}>{tier.highlight}</span>}
                                <h3 className={classes.tierName}>{tier.name}</h3>
                                <p className={classes.tierPrice}>
                                    {tier.price === 0 ? t`Free` : formatCurrency(tier.price, tier.currency)}
                                </p>
                                {tier.soldOut ? (
                                    <span className={classes.tierStatus}>{t`Sold out`}</span>
                                ) : tier.notYetOnSale ? (
                                    <span className={classes.tierStatus}>{t`On sale soon`}</span>
                                ) : (
                                    <Link to={bookingPath} className={classes.tierCta}>
                                        {t`Select`} <IconArrowRight size={16}/>
                                    </Link>
                                )}
                            </article>
                        ))}
                    </div>
                    <p className={classes.fineprint}>
                        {t`Final price shown before payment, fees included. Tickets are limited to available capacity.`}
                    </p>
                </section>
            )}

            {featured && (
                <section id="info" className={classes.section} aria-labelledby="info-title">
                    <SectionHead id="info-title" eyebrow={t`Practical info`} title={t`Everything you need to know.`}/>
                    <div className={classes.infoGrid}>
                        <div className={classes.infoItem}>
                            <IconCalendarEvent size={26}/>
                            <h3>{t`Date`}</h3>
                            <p><span className={classes.capitalize}>{longDate(featured)}</span></p>
                        </div>
                        <div className={classes.infoItem}>
                            <IconClock size={26}/>
                            <h3>{t`Time`}</h3>
                            <p>
                                {featured.end_date
                                    ? `${time(featured.start_date, featured)} – ${time(featured.end_date, featured)}`
                                    : time(featured.start_date, featured)}
                            </p>
                        </div>
                        <div className={classes.infoItem}>
                            <IconMapPin size={26}/>
                            <h3>{t`Venue`}</h3>
                            {venue?.isOnline ? <p>{t`Online event`}</p> : (
                                <>
                                    <p>{venue?.name || venue?.city}</p>
                                    {venue?.full && <p className={classes.muted}>{venue.full}</p>}
                                    {venue?.mapsUrl && (
                                        <a href={venue.mapsUrl} target="_blank" rel="noopener noreferrer" className={classes.textLink}>
                                            {t`Get directions`} <IconArrowRight size={14}/>
                                        </a>
                                    )}
                                </>
                            )}
                        </div>
                        <div className={classes.infoItem}>
                            <IconTicket size={26}/>
                            <h3>{t`Prices`}</h3>
                            <p>{fromPrice || '—'}</p>
                            <Link to={bookingPath} className={classes.textLink}>
                                {t`Event details`} <IconArrowRight size={14}/>
                            </Link>
                        </div>
                    </div>
                </section>
            )}

            {otherEvents.length > 0 && (
                <section className={classes.section} aria-labelledby="more-events-title">
                    <div className={classes.sectionHeadRow}>
                        <SectionHead id="more-events-title" eyebrow={t`Coming up`} title={t`Also coming up.`}/>
                        <Link to={sitePath('evenements')} className={classes.textLink}>
                            {t`All events`} <IconArrowRight size={14}/>
                        </Link>
                    </div>
                    <div className={classes.grid}>
                        {otherEvents.slice(0, 6).map(event => <PosterEventCard key={event.id} event={event}/>)}
                    </div>
                </section>
            )}

            {content.services && content.services.length > 0 && (
                <section className={classes.section} aria-labelledby="services-teaser-title">
                    <div className={classes.sectionHeadRow}>
                        <SectionHead id="services-teaser-title" eyebrow={t`Services`} title={t`Our expertise.`}/>
                        <Link to={sitePath('services')} className={classes.textLink}>
                            {t`Discover our services`} <IconArrowRight size={14}/>
                        </Link>
                    </div>
                    <ol className={`${classes.values} ${classes.compactValues}`}>
                        {content.services.slice(0, 4).map((service, index) => (
                            <li key={service.title} className={classes.value}>
                                <span className={classes.valueIndex}>{String(index + 1).padStart(2, '0')}</span>
                                <h3>{service.title}</h3>
                            </li>
                        ))}
                    </ol>
                </section>
            )}

            {hasUniverse && (
                <section className={classes.section} aria-labelledby="universe-title">
                    <div className={classes.universeTeaser}>
                        <div>
                            <SectionHead id="universe-title" eyebrow={t`The universe`} title={content.about_headline || organizer.name}/>
                            <p className={classes.lead}>
                                {content.tagline || stripHtml(content.story).slice(0, 260)}
                            </p>
                            <Link to={sitePath('a-propos')} className={classes.secondaryCta}>
                                {t`Discover our story and vision`} <IconArrowRight size={18}/>
                            </Link>
                        </div>
                        {content.stats && content.stats.length > 0 && <StatsBand stats={content.stats}/>}
                    </div>
                </section>
            )}

            {content.partners && content.partners.length > 0 && (
                <section className={classes.section} aria-labelledby="partners-teaser-title">
                    <div className={classes.sectionHeadRow}>
                        <SectionHead id="partners-teaser-title" eyebrow={t`Partners`} title={t`They make it happen.`}/>
                        <Link to={sitePath('partenaires')} className={classes.textLink}>
                            {t`Become a partner`} <IconArrowRight size={14}/>
                        </Link>
                    </div>
                    <PartnerLogos partners={content.partners.slice(0, 12)}/>
                </section>
            )}

            <section id="faq" className={classes.section} aria-labelledby="faq-title">
                <SectionHead id="faq-title" eyebrow={t`FAQ`} title={t`Frequently asked questions.`}/>
                <div className={classes.faq}>
                    {getFaq().map(item => (
                        <details key={item.question} className={classes.faqItem}>
                            <summary>{item.question}<IconChevronDown size={20} aria-hidden="true"/></summary>
                            <p>{item.answer}</p>
                        </details>
                    ))}
                </div>
                <button className={classes.textLink} onClick={openContact}>
                    {t`Another question? Contact the organizer`} <IconArrowRight size={14}/>
                </button>
            </section>

            {featured && !allSoldOut && (
                <section className={classes.finalCta} aria-label={t`Book now`}>
                    <h2 className={classes.finalTitle}>{t`Don't miss ${featured.title}.`}</h2>
                    <p className={classes.finalMeta}>
                        <span className={classes.capitalize}>{longDate(featured)}</span>
                        {venue?.city ? ` · ${venue.city}` : ''}
                    </p>
                    <Link to={bookingPath} className={classes.primaryCta}>
                        {bookingLabel}
                        {fromPrice && <span className={classes.ctaPrice}>{fromPrice}</span>}
                    </Link>
                </section>
            )}
        </>
    );
};
