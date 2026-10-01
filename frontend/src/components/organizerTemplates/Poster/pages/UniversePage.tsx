import {Link} from "react-router";
import {t} from "@lingui/macro";
import {IconArrowRight} from "@tabler/icons-react";
import {PageIntro, PosterPageProps, SectionHead, StatsBand} from "../shared.tsx";
import classes from "../Poster.module.scss";

export const UniversePage = ({organizer, sitePath, openContact}: PosterPageProps) => {
    const content = organizer.site_content || {};
    const hasContent = Boolean(content.story || content.vision || content.values?.length || content.team?.length);

    return (
        <>
            <PageIntro
                heading={t`${organizer.name}: story, vision and team`}
                title={content.about_headline || organizer.name}
                lead={content.tagline}
            />

            {content.stats && content.stats.length > 0 && (
                <section className={classes.section} aria-label={t`Key figures`}>
                    <StatsBand stats={content.stats}/>
                </section>
            )}

            {content.story && (
                <section className={classes.section} aria-labelledby="story-title">
                    <div className={classes.split}>
                        <div>
                            <SectionHead id="story-title" eyebrow={t`Our story`} title={t`Where it all began.`}/>
                            <div className={classes.richText} dangerouslySetInnerHTML={{__html: content.story}}/>
                        </div>
                        {content.about_image_url && (
                            <img src={content.about_image_url} alt={organizer.name} className={classes.splitImage} loading="lazy"/>
                        )}
                    </div>
                </section>
            )}

            {content.vision && (
                <section className={classes.section} aria-labelledby="vision-title">
                    <div className={classes.visionBlock}>
                        <SectionHead id="vision-title" eyebrow={t`Our vision`} title={t`Where we are going.`}/>
                        <div className={classes.visionText} dangerouslySetInnerHTML={{__html: content.vision}}/>
                    </div>
                </section>
            )}

            {content.values && content.values.length > 0 && (
                <section className={classes.section} aria-labelledby="values-title">
                    <SectionHead id="values-title" eyebrow={t`Our values`} title={t`What drives us.`}/>
                    <ol className={classes.values}>
                        {content.values.map((value, index) => (
                            <li key={value.title} className={classes.value}>
                                <span className={classes.valueIndex}>{String(index + 1).padStart(2, '0')}</span>
                                <h3>{value.title}</h3>
                                {value.text && <p>{value.text}</p>}
                            </li>
                        ))}
                    </ol>
                </section>
            )}

            {content.team && content.team.length > 0 && (
                <section className={classes.section} aria-labelledby="team-title">
                    <SectionHead id="team-title" eyebrow={t`The team`} title={t`The people behind the events.`}/>
                    <ul className={classes.team}>
                        {content.team.map(member => (
                            <li key={member.name} className={classes.member}>
                                <div className={classes.memberPhoto}>
                                    {member.photo_url
                                        ? <img src={member.photo_url} alt={member.name} loading="lazy"/>
                                        : <span aria-hidden="true">{member.name.slice(0, 1)}</span>}
                                </div>
                                <h3>{member.name}</h3>
                                {member.role && <p>{member.role}</p>}
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {!hasContent && (
                <section className={classes.section}>
                    <p className={classes.empty}>{t`Our story will be told here very soon.`}</p>
                </section>
            )}

            <section className={classes.finalCta} aria-label={t`Next steps`}>
                <h2 className={classes.finalTitle}>{t`Live the experience.`}</h2>
                <div className={classes.heroActions}>
                    <Link to={sitePath('evenements')} className={classes.primaryCta}>{t`See the events`}</Link>
                    <button className={classes.secondaryCta} onClick={openContact}>
                        {t`Contact us`} <IconArrowRight size={18}/>
                    </button>
                </div>
            </section>
        </>
    );
};
