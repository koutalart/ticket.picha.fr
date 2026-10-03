/* eslint-disable lingui/no-unlocalized-strings */
import {Link, useParams} from "react-router";
import {Helmet} from "react-helmet-async";
import {IconCalendarEvent, IconCheck, IconChevronRight, IconMapPin, IconUsers} from "@tabler/icons-react";
import {MarketingHeader} from "../../landing/MarketingHeader";
import {MarketingFooter} from "../../landing/MarketingFooter";
import {MarketingDemoSection} from "../../landing/MarketingDemoSection";
import {getSolution, solutionPath} from "../../solutions/solutions.ts";
import {getAppName, SHARE_IMAGE_PATH} from "../../../../utilites/branding.ts";
import {getConfig} from "../../../../utilites/config.ts";
import {CaseStudyCard} from "../CaseStudyCard";
import {caseStudies, getCaseStudyTitle, getVisibleCaseStudies, isCaseStudyVisible, serviceLabels} from "../caseStudies.ts";
import classes from "../CaseStudies.module.scss";

const ToComplete = ({children}: { children: string }) => (
    import.meta.env.DEV ? <p className={classes.todo}>À compléter : {children}</p> : null
);

const CaseStudyPage = () => {
    const {slug} = useParams();
    const caseStudy = caseStudies.find((entry) => entry.slug === slug);
    const frontendUrl = (getConfig("VITE_FRONTEND_URL") || "").replace(/\/$/, "");

    if (!caseStudy || !isCaseStudyVisible(caseStudy)) {
        return (
            <div className={classes.page}>
                <Helmet>
                    <title>{`Cas client introuvable | ${getAppName()}`}</title>
                    <meta name="robots" content="noindex"/>
                </Helmet>
                <MarketingHeader/>
                <main className={classes.main} lang="fr">
                    <section className={classes.intro}>
                        <h1 className={classes.title}>Cette étude de cas n'est pas encore disponible</h1>
                        <p className={classes.lead}><Link to="/cas-clients">Voir tous nos cas clients</Link></p>
                    </section>
                </main>
                <MarketingFooter/>
            </div>
        );
    }

    const title = getCaseStudyTitle(caseStudy);
    const pageUrl = `${frontendUrl}/cas-clients/${caseStudy.slug}`;
    const description = caseStudy.summary
        ?? `${title} : inscriptions, accueil et contrôle d'accès avec ${getAppName()}.`;
    const facts = [
        {icon: IconCalendarEvent, label: "Date", value: caseStudy.date},
        {icon: IconMapPin, label: "Lieu", value: caseStudy.location},
        {icon: IconUsers, label: "Participants", value: caseStudy.attendees},
    ].filter((fact) => fact.value);
    const others = getVisibleCaseStudies().filter((entry) => entry.slug !== caseStudy.slug).slice(0, 3);
    const headline = caseStudy.headline ?? title;
    const usedSolutions = (caseStudy.solutions ?? []).map(getSolution).filter((solution) => solution !== undefined);
    const event = caseStudy.eventStartDate ? {
        "@type": "Event",
        "@id": `${pageUrl}#event`,
        name: title,
        startDate: caseStudy.eventStartDate,
        endDate: caseStudy.eventEndDate,
        eventStatus: "https://schema.org/EventScheduled",
        location: caseStudy.eventLocality ? {
            "@type": "Place",
            name: caseStudy.location ?? caseStudy.eventLocality,
            address: {"@type": "PostalAddress", addressLocality: caseStudy.eventLocality, addressCountry: caseStudy.eventCountryCode},
        } : undefined,
        organizer: {"@type": "Organization", name: caseStudy.client},
    } : undefined;
    const structuredData = {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "BreadcrumbList",
                itemListElement: [
                    {"@type": "ListItem", position: 1, name: getAppName(), item: `${frontendUrl}/`},
                    {"@type": "ListItem", position: 2, name: "Cas clients", item: `${frontendUrl}/cas-clients`},
                    {"@type": "ListItem", position: 3, name: title, item: pageUrl},
                ],
            },
            {
                "@type": "Article",
                "@id": `${pageUrl}#article`,
                headline,
                description,
                inLanguage: "fr",
                mainEntityOfPage: pageUrl,
                datePublished: caseStudy.publishedAt,
                image: caseStudy.coverImage,
                author: {"@type": "Organization", name: "PICHA", url: getConfig("VITE_BRAND_URL", "https://picha.fr")},
                publisher: {"@type": "Organization", name: "PICHA", url: getConfig("VITE_BRAND_URL", "https://picha.fr")},
                about: event ? {"@id": `${pageUrl}#event`} : caseStudy.client,
                mentions: usedSolutions.map((solution) => ({"@type": "Service", name: solution.name, url: `${frontendUrl}${solutionPath(solution.slug)}`})),
            },
            ...(event ? [event] : []),
        ],
    };

    return (
        <div className={classes.page}>
            <Helmet>
                <title>{`${title} | Cas client ${getAppName()}`}</title>
                <meta name="description" content={description}/>
                <link rel="canonical" href={pageUrl}/>
                {caseStudy.draft && <meta name="robots" content="noindex"/>}
                <meta property="og:type" content="article"/>
                <meta property="og:title" content={`${title} | Cas client ${getAppName()}`}/>
                <meta property="og:description" content={description}/>
                <meta property="og:url" content={pageUrl}/>
                <meta property="og:image" content={caseStudy.coverImage ?? `${frontendUrl}${SHARE_IMAGE_PATH}`}/>
                <meta name="twitter:card" content="summary_large_image"/>
                <script type="application/ld+json">{JSON.stringify(structuredData).replace(/</g, "\\u003c")}</script>
            </Helmet>

            <MarketingHeader/>

            <main className={classes.main} lang="fr">
                {caseStudy.draft && import.meta.env.DEV && (
                    <p className={classes.draftBanner}>Brouillon : cette page n'est ni listée ni indexée en production tant que « draft » vaut true.</p>
                )}

                <nav className={classes.breadcrumb} aria-label="Fil d'Ariane">
                    <Link to="/cas-clients">Cas clients</Link>
                    <IconChevronRight size={14} aria-hidden="true"/>
                    <span aria-current="page">{title}</span>
                </nav>

                <section className={classes.intro}>
                    {caseStudy.sector && <p className={classes.eyebrow}>{caseStudy.sector}</p>}
                    <h1 className={classes.title}>{headline}</h1>
                    {caseStudy.summary
                        ? <p className={classes.lead}>{caseStudy.summary}</p>
                        : <ToComplete>résumé en une ou deux phrases (le résultat principal pour le client).</ToComplete>}
                    {facts.length > 0 ? (
                        <ul className={classes.facts}>
                            {facts.map((fact) => (
                                <li key={fact.label}>
                                    <fact.icon size={18} aria-hidden="true"/>
                                    <span className={classes.factLabel}>{fact.label}</span> {fact.value}
                                </li>
                            ))}
                        </ul>
                    ) : <ToComplete>date, lieu et nombre de participants.</ToComplete>}
                </section>

                <div className={classes.cover}>
                    {caseStudy.coverImage
                        ? <img src={caseStudy.coverImage} alt={caseStudy.coverAlt ?? ""}/>
                        : <span aria-hidden="true">{caseStudy.client}</span>}
                </div>

                <div className={classes.article}>
                    <section className={classes.block}>
                        <h2>Le contexte</h2>
                        {caseStudy.challenge
                            ? <p>{caseStudy.challenge}</p>
                            : <ToComplete>l'événement, le public attendu et ce qui devait être réussi à l'accueil.</ToComplete>}
                    </section>

                    <section className={classes.block}>
                        <h2>Le dispositif {getAppName()}</h2>
                        {caseStudy.solution && caseStudy.solution.length > 0 ? (
                            <ul className={classes.checkList}>
                                {caseStudy.solution.map((item) => (
                                    <li key={item}><IconCheck size={18} stroke={2.2} aria-hidden="true"/>{item}</li>
                                ))}
                            </ul>
                        ) : <ToComplete>ce qui a été mis en place (formule accompagnée ou location, badges ou bracelets, nombre d'agents…).</ToComplete>}
                        {caseStudy.services.length > 0 && (
                            <ul className={classes.services} aria-label="Services utilisés">
                                {caseStudy.services.map((service) => <li key={service}>{serviceLabels[service]}</li>)}
                            </ul>
                        )}
                        {usedSolutions.length > 0 && (
                            <p className={classes.solutionLinks}>
                                En savoir plus :{" "}
                                {usedSolutions.map((solution, index) => (
                                    <span key={solution.slug}>
                                        {index > 0 && ", "}
                                        <Link to={solutionPath(solution.slug)}>{solution.name}</Link>
                                    </span>
                                ))}
                            </p>
                        )}
                    </section>

                    <section className={classes.block}>
                        <h2>Le déroulé du jour J</h2>
                        {caseStudy.dayOf
                            ? <p>{caseStudy.dayOf}</p>
                            : <ToComplete>comment s'est passé l'accueil : installation, ouverture des portes, pics d'affluence, imprévus gérés.</ToComplete>}
                    </section>

                    <section className={classes.block}>
                        <h2>Les résultats</h2>
                        {caseStudy.results && caseStudy.results.length > 0 ? (
                            <div className={classes.results}>
                                {caseStudy.results.map((result) => (
                                    <div key={result.label} className={classes.result}>
                                        <strong>{result.value}</strong>
                                        <span>{result.label}</span>
                                    </div>
                                ))}
                            </div>
                        ) : <ToComplete>2 à 4 chiffres réels (participants accueillis, temps d'attente, taux de présence…).</ToComplete>}
                    </section>

                    {caseStudy.photos && caseStudy.photos.length > 0 ? (
                        <div className={classes.photos}>
                            {caseStudy.photos.slice(0, 2).map((photo) => (
                                <img key={photo.src} src={photo.src} alt={photo.alt} loading="lazy"/>
                            ))}
                        </div>
                    ) : <ToComplete>2 photos maximum : l'accueil avec les équipes qui scannent, et une vue d'ensemble de l'événement.</ToComplete>}

                    {caseStudy.quote && (caseStudy.quote.approved || import.meta.env.DEV) ? (
                        <figure className={classes.quote}>
                            {!caseStudy.quote.approved && (
                                <p className={classes.todo}>Citation proposée, en attente de l'accord écrit de la personne : elle n'apparaît pas en production tant que « approved » vaut false.</p>
                            )}
                            <blockquote>« {caseStudy.quote.text} »</blockquote>
                            <figcaption>
                                <strong>{caseStudy.quote.author}</strong>, {caseStudy.quote.role}
                            </figcaption>
                        </figure>
                    ) : !caseStudy.quote && <ToComplete>citation du client, avec son nom, sa fonction et son accord écrit.</ToComplete>}
                </div>


                {others.length > 0 && (
                    <section className={classes.others} aria-labelledby="others-title">
                        <h2 id="others-title">Autres cas clients</h2>
                        <div className={classes.grid}>
                            {others.map((entry) => <CaseStudyCard key={entry.slug} caseStudy={entry}/>)}
                        </div>
                    </section>
                )}
            </main>

            <MarketingDemoSection
                title="Organiser un accueil similaire"
                text={`Présentez-nous votre événement : nous vous montrons comment reproduire le dispositif de ${caseStudy.client} à votre échelle.`}
            />

            <MarketingFooter/>
        </div>
    );
};

export default CaseStudyPage;
