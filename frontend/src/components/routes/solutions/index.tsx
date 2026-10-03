/* eslint-disable lingui/no-unlocalized-strings */
import {Link, useLocation} from "react-router";
import {Helmet} from "react-helmet-async";
import {IconArrowRight, IconCalendarEvent, IconCheck, IconChevronRight, IconHeadset, IconServer, IconShieldCheck, IconShoppingBag} from "@tabler/icons-react";
import {MarketingHeader} from "../landing/MarketingHeader";
import {MarketingFooter} from "../landing/MarketingFooter";
import {MarketingDemoSection} from "../landing/MarketingDemoSection";
import {CaseStudyCard} from "../case-studies/CaseStudyCard";
import {getCaseStudiesForSolution} from "../case-studies/caseStudies.ts";
import {getAppName, SHARE_IMAGE_PATH} from "../../../utilites/branding.ts";
import {getConfig} from "../../../utilites/config.ts";
import {getSolution, SolutionCta, solutionPath, solutions} from "./solutions.ts";
import classes from "./Solutions.module.scss";

const SolutionPage = () => {
    const {pathname} = useLocation();
    const solution = getSolution(pathname.replace(/^\/|\/$/g, "")) ?? solutions[0];
    const frontendUrl = (getConfig("VITE_FRONTEND_URL") || "").replace(/\/$/, "");
    const shopUrl = getConfig("VITE_SHOP_URL");
    const pageUrl = `${frontendUrl}${solutionPath(solution.slug)}`;
    const caseStudies = getCaseStudiesForSolution(solution.slug);
    const otherSolutions = solutions.filter((entry) => entry.slug !== solution.slug);

    const ctaHref = (cta: SolutionCta) => {
        if (cta.target === "shop") {
            return shopUrl || "#demo";
        }
        return cta.target === "how" ? "/#how-it-works" : "#demo";
    };
    const ctaLabel = (cta: SolutionCta) => cta.target === "shop" && !shopUrl ? "Demander un devis de location" : cta.label;

    const structuredData = {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "WebPage",
                "@id": `${pageUrl}#webpage`,
                url: pageUrl,
                name: solution.metaTitle,
                description: solution.metaDescription,
                inLanguage: "fr",
                breadcrumb: {
                    "@type": "BreadcrumbList",
                    itemListElement: [
                        {"@type": "ListItem", position: 1, name: getAppName(), item: `${frontendUrl}/`},
                        {"@type": "ListItem", position: 2, name: solution.name, item: pageUrl},
                    ],
                },
            },
            {
                "@type": "Service",
                "@id": `${pageUrl}#service`,
                name: solution.name,
                description: solution.metaDescription,
                serviceType: solution.name,
                provider: {"@type": "Organization", name: "PICHA", url: getConfig("VITE_BRAND_URL", "https://picha.fr")},
                url: pageUrl,
            },
            {
                "@type": "FAQPage",
                "@id": `${pageUrl}#faq`,
                mainEntity: solution.faq.map((entry) => ({
                    "@type": "Question",
                    name: entry.question,
                    acceptedAnswer: {"@type": "Answer", text: entry.answer},
                })),
            },
        ],
    };

    return (
        <div className={classes.page}>
            <Helmet>
                <title>{solution.metaTitle}</title>
                <meta name="description" content={solution.metaDescription}/>
                <link rel="canonical" href={pageUrl}/>
                <meta property="og:type" content="website"/>
                <meta property="og:site_name" content={getAppName()}/>
                <meta property="og:locale" content="fr_FR"/>
                <meta property="og:title" content={solution.metaTitle}/>
                <meta property="og:description" content={solution.metaDescription}/>
                <meta property="og:url" content={pageUrl}/>
                <meta property="og:image" content={`${frontendUrl}${SHARE_IMAGE_PATH}`}/>
                <meta property="og:image:width" content="1200"/>
                <meta property="og:image:height" content="630"/>
                <meta name="twitter:card" content="summary_large_image"/>
                <meta name="twitter:image" content={`${frontendUrl}${SHARE_IMAGE_PATH}`}/>
                <script type="application/ld+json">{JSON.stringify(structuredData).replace(/</g, "\\u003c")}</script>
            </Helmet>

            <MarketingHeader/>

            <main lang="fr">
                <div className={classes.container}>
                    <nav className={classes.breadcrumb} aria-label="Fil d'Ariane">
                        <Link to="/">{getAppName()}</Link>
                        <IconChevronRight size={14} aria-hidden="true"/>
                        <span aria-current="page">{solution.name}</span>
                    </nav>

                    <section className={classes.hero} aria-labelledby="solution-title">
                        <p className={classes.badge}>{solution.name}</p>
                        <h1 id="solution-title" className={classes.title}>{solution.headline}</h1>
                        <p className={classes.lead}>{solution.intro}</p>
                        <div className={classes.actions}>
                            <a href={ctaHref(solution.primaryCta)} className={classes.primaryButton}>
                                {solution.primaryCta.target === "shop"
                                    ? <IconShoppingBag size={20} aria-hidden="true"/>
                                    : <IconCalendarEvent size={20} aria-hidden="true"/>}
                                {ctaLabel(solution.primaryCta)}
                            </a>
                            <span className={classes.responseTime}>Réponse sous 1 jour ouvré</span>
                        </div>
                        <ul className={classes.trust} aria-label="Nos engagements">
                            <li><IconServer size={18} aria-hidden="true"/>Hébergé en France</li>
                            <li><IconShieldCheck size={18} aria-hidden="true"/>RGPD</li>
                            <li><IconHeadset size={18} aria-hidden="true"/>Accompagnement le jour J</li>
                        </ul>
                    </section>
                </div>

                {solution.sections.map((section, index) => (
                    <section key={section.title} className={`${classes.section} ${index % 2 === 0 ? classes.sectionMuted : ""}`}>
                        <div className={classes.containerNarrow}>
                            <h2 className={classes.sectionTitle}>{section.title}</h2>
                            {section.text && <p className={classes.sectionText}>{section.text}</p>}
                            {section.items && (
                                <ul className={classes.checkList}>
                                    {section.items.map((item) => (
                                        <li key={item}><IconCheck size={18} stroke={2.2} aria-hidden="true"/>{item}</li>
                                    ))}
                                </ul>
                            )}
                            {section.steps && (
                                <ol className={classes.steps}>
                                    {section.steps.map((step, stepIndex) => (
                                        <li key={step.title}>
                                            <span className={classes.stepNumber} aria-hidden="true">{stepIndex + 1}</span>
                                            <h3>{step.title}</h3>
                                            <p>{step.text}</p>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </div>
                    </section>
                ))}

                {caseStudies.length > 0 && (
                    <section className={classes.section} aria-labelledby="solution-cases">
                        <div className={classes.container}>
                            <h2 id="solution-cases" className={classes.sectionTitle}>Ils l'ont mis en place</h2>
                            <div className={classes.grid}>
                                {caseStudies.map((caseStudy) => <CaseStudyCard key={caseStudy.slug} caseStudy={caseStudy}/>)}
                            </div>
                            <p className={classes.moreLink}>
                                <Link to="/cas-clients">Voir tous les cas clients <IconArrowRight size={16} aria-hidden="true"/></Link>
                            </p>
                        </div>
                    </section>
                )}

                <section className={`${classes.section} ${classes.sectionMuted}`} aria-labelledby="solution-faq">
                    <div className={classes.containerNarrow}>
                        <h2 id="solution-faq" className={classes.sectionTitle}>Questions fréquentes</h2>
                        <div className={classes.faq}>
                            {solution.faq.map((entry) => (
                                <details key={entry.question} className={classes.faqItem}>
                                    <summary>{entry.question}</summary>
                                    <p>{entry.answer}</p>
                                </details>
                            ))}
                        </div>
                    </div>
                </section>

                <section className={classes.section} aria-labelledby="solution-others">
                    <div className={classes.container}>
                        <h2 id="solution-others" className={classes.sectionTitle}>Nos autres solutions</h2>
                        <ul className={classes.others}>
                            {otherSolutions.map((entry) => (
                                <li key={entry.slug}>
                                    <Link to={solutionPath(entry.slug)}>
                                        <strong>{entry.name}</strong>
                                        <span>{entry.metaDescription}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                {solution.finalCta.target === "shop" && shopUrl ? (
                    <section className={classes.finalShop}>
                        <h2>{solution.finalTitle}</h2>
                        <a href={shopUrl} className={classes.primaryButton}>
                            <IconShoppingBag size={20} aria-hidden="true"/>{solution.finalCta.label}
                        </a>
                    </section>
                ) : (
                    <MarketingDemoSection
                        title={solution.finalTitle}
                        text="Présentez-nous votre événement : nous vous montrons comment PICHA Ticket s'adapte à votre accueil."
                    />
                )}
            </main>

            <MarketingFooter/>
        </div>
    );
};

export default SolutionPage;
