import {Link} from "react-router";
import {t} from "@lingui/macro";
import {IconArrowRight} from "@tabler/icons-react";
import {organizerHomepagePath} from "../../../../utilites/urlHelper.ts";
import {areaSuffix, PageIntro, PosterEventCard, PosterPageProps, SectionHead} from "../shared.tsx";
import classes from "../Poster.module.scss";

export const EventsPage = ({organizer, eventsData, pastEventsData}: PosterPageProps) => {
    const content = organizer.site_content || {};
    const upcoming = eventsData?.data || [];
    const past = pastEventsData?.data || [];
    const gallery = content.gallery || [];

    return (
        <>
            <PageIntro
                heading={t`Events${areaSuffix(organizer)} — ${organizer.name}`}
                title={t`Line-up & archives.`}
                lead={t`All upcoming dates by ${organizer.name}, and a look back at past editions.`}
            />

            <section className={classes.section} aria-labelledby="upcoming-title">
                <SectionHead id="upcoming-title" eyebrow={t`Coming up`} title={t`Upcoming events.`}/>
                {upcoming.length > 0 ? (
                    <div className={classes.grid}>
                        {upcoming.map(event => <PosterEventCard key={event.id} event={event}/>)}
                    </div>
                ) : (
                    <p className={classes.empty}>{t`Other dates will be announced soon. Follow us so you don't miss them.`}</p>
                )}
            </section>

            {past.length > 0 && (
                <section className={classes.section} aria-labelledby="past-title">
                    <div className={classes.sectionHeadRow}>
                        <SectionHead id="past-title" eyebrow={t`Archive`} title={t`Past events.`}/>
                        {(pastEventsData?.meta.last_page || 1) > 1 && (
                            <Link to={`${organizerHomepagePath(organizer)}/past-events`} className={classes.textLink}>
                                {t`See all past events`} <IconArrowRight size={14}/>
                            </Link>
                        )}
                    </div>
                    <div className={classes.grid}>
                        {past.map(event => <PosterEventCard key={event.id} event={event} past/>)}
                    </div>
                </section>
            )}

            {gallery.length > 0 && (
                <section className={classes.section} aria-labelledby="gallery-title">
                    <SectionHead id="gallery-title" eyebrow={t`Gallery`} title={t`Best moments.`}/>
                    <ul className={classes.gallery}>
                        {gallery.map(photo => (
                            <li key={photo.url}>
                                <figure>
                                    <img src={photo.url} alt={photo.caption || organizer.name} loading="lazy"/>
                                    {photo.caption && <figcaption>{photo.caption}</figcaption>}
                                </figure>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </>
    );
};
