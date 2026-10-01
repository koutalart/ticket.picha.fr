import {Link} from "react-router";
import {t} from "@lingui/macro";
import {IconArrowRight} from "@tabler/icons-react";
import {areaSuffix, PageIntro, PosterPageProps, SectionHead} from "../shared.tsx";
import classes from "../Poster.module.scss";

export const ServicesPage = ({organizer, sitePath, openContact}: PosterPageProps) => {
    const content = organizer.site_content || {};
    const services = content.services || [];

    return (
        <>
            <PageIntro
                heading={t`${organizer.name} services${areaSuffix(organizer)}`}
                title={t`Your event, from idea to stage.`}
                lead={content.services_intro || t`${organizer.name} supports companies, institutions and partners in creating their events.`}
            />

            {services.length > 0 && (
                <section className={classes.section} aria-labelledby="services-title">
                    <SectionHead id="services-title" eyebrow={t`What we do`} title={t`Our expertise.`}/>
                    <ol className={classes.values}>
                        {services.map((service, index) => (
                            <li key={service.title} className={classes.value}>
                                <span className={classes.valueIndex}>{String(index + 1).padStart(2, '0')}</span>
                                <h3>{service.title}</h3>
                                {service.text && <p>{service.text}</p>}
                            </li>
                        ))}
                    </ol>
                </section>
            )}

            <section className={classes.finalCta} aria-label={t`Request a quote`}>
                <h2 className={classes.finalTitle}>{t`Let's build your next event.`}</h2>
                <p className={classes.finalMeta}>{t`Tell us about your project: we will get back to you with a tailored proposal.`}</p>
                <div className={classes.heroActions}>
                    <button className={classes.primaryCta} onClick={openContact}>{t`Request a quote`}</button>
                    <Link to={sitePath('evenements')} className={classes.secondaryCta}>
                        {t`See our events`} <IconArrowRight size={18}/>
                    </Link>
                </div>
            </section>
        </>
    );
};
