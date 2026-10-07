/* eslint-disable lingui/no-unlocalized-strings */
import {Helmet} from "react-helmet-async";
import {MarketingHeader} from "../landing/MarketingHeader";
import {MarketingFooter} from "../landing/MarketingFooter";
import {MarketingDemoSection} from "../landing/MarketingDemoSection";
import {getAppName, SHARE_IMAGE_PATH} from "../../../utilites/branding.ts";
import {getConfig} from "../../../utilites/config.ts";
import {CaseStudyCard} from "./CaseStudyCard";
import {getVisibleCaseStudies} from "./caseStudies.ts";
import classes from "./CaseStudies.module.scss";

const CaseStudiesPage = () => {
    const caseStudies = getVisibleCaseStudies();
    const frontendUrl = (getConfig("VITE_FRONTEND_URL") || "").replace(/\/$/, "");
    const title = `Cas clients : ils ont confié leur accueil à ${getAppName()}`;
    const description = `Festival à Nosy Be, concert et journée portes ouvertes à Mayotte : comment nos clients vendent leurs billets et contrôlent leurs entrées avec ${getAppName()}.`;

    return (
        <div className={classes.page}>
            <Helmet>
                <title>{`Cas clients : billetterie et contrôle d'accès | ${getAppName()}`}</title>
                <meta name="description" content={description}/>
                <link rel="canonical" href={`${frontendUrl}/cas-clients`}/>
                <meta property="og:title" content={title}/>
                <meta property="og:description" content={description}/>
                <meta property="og:url" content={`${frontendUrl}/cas-clients`}/>
                <meta property="og:image" content={`${frontendUrl}${SHARE_IMAGE_PATH}`}/>
                <meta name="twitter:card" content="summary_large_image"/>
            </Helmet>

            <MarketingHeader/>

            <main className={classes.main} lang="fr">
                <section className={classes.intro}>
                    <p className={classes.eyebrow}>Cas clients</p>
                    <h1 className={classes.title}>Des événements accueillis avec fluidité, par des équipes qui gardent le contrôle</h1>
                    <p className={classes.lead}>
                        Festival, concert, journée portes ouvertes : découvrez comment chaque organisateur a vendu ses billets, géré ses inscriptions et contrôlé ses entrées avec {getAppName()}.
                    </p>
                </section>

                {caseStudies.length > 0 ? (
                    <div className={classes.grid}>
                        {caseStudies.map((caseStudy) => (
                            <CaseStudyCard key={caseStudy.slug} caseStudy={caseStudy} headingLevel="h2"/>
                        ))}
                    </div>
                ) : (
                    <p className={classes.empty}>Nos premières études de cas arrivent très bientôt.</p>
                )}

            </main>

            <MarketingDemoSection
                title="Votre événement peut être le prochain à gagner en fluidité"
                text="Présentez-nous votre projet : nous vous répondons sous 1 jour ouvré."
            />

            <MarketingFooter/>
        </div>
    );
};

export default CaseStudiesPage;
