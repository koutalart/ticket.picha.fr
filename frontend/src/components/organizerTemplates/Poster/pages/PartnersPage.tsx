import {t} from "@lingui/macro";
import {IconArrowRight, IconChartBar, IconSpeakerphone, IconUsersGroup} from "@tabler/icons-react";
import {PageIntro, PartnerLogos, PosterPageProps, SectionHead} from "../shared.tsx";
import classes from "../Poster.module.scss";

export const PartnersPage = ({organizer, openContact}: PosterPageProps) => {
    const content = organizer.site_content || {};
    const partners = content.partners || [];

    const groups = partners.reduce<Record<string, typeof partners>>((acc, partner) => {
        const key = partner.category || '';
        (acc[key] = acc[key] || []).push(partner);
        return acc;
    }, {});

    return (
        <>
            <PageIntro
                heading={t`Partners — ${organizer.name}`}
                title={t`Together, further.`}
                lead={content.partners_intro || t`Brands, institutions and media that support ${organizer.name}.`}
            />

            {Object.entries(groups).map(([category, list], index) => (
                <section key={category || 'all'} className={classes.section} aria-labelledby={`partners-${index}`}>
                    <SectionHead id={`partners-${index}`} eyebrow={t`Partners`} title={category || t`Our partners.`}/>
                    <PartnerLogos partners={list}/>
                </section>
            ))}

            <section className={classes.section} aria-labelledby="become-partner-title">
                <div className={classes.visionBlock}>
                    <SectionHead id="become-partner-title" eyebrow={t`Become a partner`} title={t`Associate your brand with our events.`}/>
                    <ul className={classes.benefits}>
                        <li><IconUsersGroup size={26}/><strong>{t`Reach an engaged audience`}</strong><span>{t`Meet our audience where emotions run high.`}</span></li>
                        <li><IconSpeakerphone size={26}/><strong>{t`Gain visibility`}</strong><span>{t`On site, on our communication materials and on social media.`}</span></li>
                        <li><IconChartBar size={26}/><strong>{t`Tailored partnership`}</strong><span>{t`Sponsorship, co-branding, hospitality: let's build it together.`}</span></li>
                    </ul>
                    <button className={classes.primaryCta} onClick={openContact}>
                        {t`Contact us to become a partner`} <IconArrowRight size={18}/>
                    </button>
                </div>
            </section>
        </>
    );
};
