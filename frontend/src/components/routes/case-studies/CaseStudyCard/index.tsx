import {Link} from "react-router";
import {t} from "@lingui/macro";
import {IconArrowRight} from "@tabler/icons-react";
import {CaseStudy, getCaseStudyTitle} from "../caseStudies.ts";
import classes from "./CaseStudyCard.module.scss";

interface CaseStudyCardProps {
    caseStudy: CaseStudy;
    headingLevel?: "h2" | "h3";
}

export const CaseStudyCard = ({caseStudy, headingLevel = "h3"}: CaseStudyCardProps) => {
    const Heading = headingLevel;

    return (
        <article className={classes.card} lang="fr">
            <div className={classes.cover} aria-hidden={!caseStudy.coverImage}>
                {caseStudy.coverImage
                    ? <img src={caseStudy.coverImage} alt={caseStudy.coverAlt ?? ""} loading="lazy"/>
                    : <span className={classes.coverClient}>{caseStudy.client}</span>}
            </div>
            <div className={classes.body}>
                {caseStudy.sector && <p className={classes.sector}>{caseStudy.sector}</p>}
                <Heading className={classes.title}>
                    <Link to={`/cas-clients/${caseStudy.slug}`} className={classes.link}>
                        {getCaseStudyTitle(caseStudy)}
                    </Link>
                </Heading>
                {caseStudy.summary && <p className={classes.summary}>{caseStudy.summary}</p>}
                <span className={classes.more} aria-hidden="true">
                    {t`Read the case study`} <IconArrowRight size={16}/>
                </span>
            </div>
        </article>
    );
};
