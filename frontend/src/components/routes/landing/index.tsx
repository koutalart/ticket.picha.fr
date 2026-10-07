import {useEffect, useRef, useState} from "react";
import {Link} from "react-router";
import {Helmet} from "react-helmet-async";
import {t, Trans} from "@lingui/macro";
import {i18n} from "@lingui/core";
import {
    IconArrowRight,
    IconBuildingBank,
    IconBuildingSkyscraper,
    IconCalendarEvent,
    IconChartBar,
    IconCheck,
    IconClockHour4,
    IconDeviceTablet,
    IconHeadset,
    IconLock,
    IconMailForward,
    IconScan,
    IconServer,
    IconShieldCheck,
    IconShoppingBag,
    IconUsersGroup,
} from "@tabler/icons-react";
import {getAppName, getLogoForLightBackground, getPrivacyPolicyUrl, SHARE_IMAGE_PATH} from "../../../utilites/branding.ts";
import {getConfig} from "../../../utilites/config.ts";
import {MarketingDemoSection} from "./MarketingDemoSection";
import {HeroVideo} from "./HeroVideo";
import {MarketingHeader} from "./MarketingHeader";
import {MarketingFooter} from "./MarketingFooter";
import {CaseStudyCard} from "../case-studies/CaseStudyCard";
import {getVisibleCaseStudies} from "../case-studies/caseStudies.ts";
import {InvitationPanel, LiveDashboard, PrintedBadge, ScanScreen} from "./ProductVisuals";
import classes from "./Landing.module.scss";

const CONTACT_EMAIL = "ticket@picha.fr";
const DEMO_ANCHOR = "#demo";

const toOpenGraphLocale = (locale: string) => {
    const [language, region] = locale.split("-");
    return `${language}_${(region || language).toUpperCase()}`;
};

const Landing = () => {
    const appName = getAppName();
    const frontendUrl = (getConfig("VITE_FRONTEND_URL") || "").replace(/\/$/, "");
    const brandUrl = getConfig("VITE_BRAND_URL", "https://picha.fr") as string;
    const canonicalUrl = `${frontendUrl}/`;
    const shopUrl = getConfig("VITE_SHOP_URL") || "/location-materiel-evenementiel";
    const logoUrl = getLogoForLightBackground();
    const absoluteLogoUrl = logoUrl.startsWith("http") ? logoUrl : `${frontendUrl}${logoUrl}`;

    const pageTitle = t`Professional event management software | ${appName}`;
    const pageDescription = t`Manage registrations, welcome, digital check-in and badge printing for your professional and institutional events with ${appName}.`;

    const featuredCaseStudies = getVisibleCaseStudies().slice(0, 3);
    const hasCaseStudies = featuredCaseStudies.length > 0;
    const sectionTone = (mutedWhenCaseStudies: boolean) =>
        `${classes.section} ${mutedWhenCaseStudies === hasCaseStudies ? classes.sectionMuted : ""}`;

    const heroCtaRef = useRef<HTMLDivElement>(null);
    const demoSectionRef = useRef<HTMLElement>(null);
    const [showStickyCta, setShowStickyCta] = useState(false);

    useEffect(() => {
        if (typeof IntersectionObserver === "undefined" || !heroCtaRef.current || !demoSectionRef.current) {
            return;
        }

        const visibility = {hero: true, demo: false};
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.target === heroCtaRef.current) {
                    visibility.hero = entry.isIntersecting;
                }
                if (entry.target === demoSectionRef.current) {
                    visibility.demo = entry.isIntersecting;
                }
            });
            setShowStickyCta(!visibility.hero && !visibility.demo);
        });

        observer.observe(heroCtaRef.current);
        observer.observe(demoSectionRef.current);

        return () => observer.disconnect();
    }, []);

    const structuredData = {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "Organization",
                "@id": `${brandUrl}#organization`,
                name: "PICHA",
                url: brandUrl,
                logo: absoluteLogoUrl,
                email: CONTACT_EMAIL,
                address: {"@type": "PostalAddress", addressCountry: "FR"},
            },
            {
                "@type": "SoftwareApplication",
                "@id": `${canonicalUrl}#software`,
                name: appName,
                url: canonicalUrl,
                description: pageDescription,
                applicationCategory: "BusinessApplication",
                applicationSubCategory: t`Event management software`,
                operatingSystem: "Web",
                inLanguage: i18n.locale,
                featureList: [
                    t`Invitations and online registration`,
                    t`QR code tickets`,
                    t`QR code access control`,
                    t`Digital attendance sheet`,
                    t`On-site badge printing`,
                    t`Real-time attendance reporting`,
                ],
                publisher: {"@id": `${brandUrl}#organization`},
            },
        ],
    };

    const benefits = [
        {
            icon: IconMailForward,
            moment: t`Before the event`,
            title: t`Registrations that run themselves`,
            text: t`Personalized invitations, a registration page with your own questions, free or paid tickets: every attendee receives their QR code badge or ticket by e-mail.`,
        },
        {
            icon: IconScan,
            moment: t`During the event`,
            title: t`A welcome without queues`,
            text: t`One scan of the QR code and the entry is recorded: the badge prints or the wristband is handed over right away. Last-minute registrations are done on a tablet.`,
        },
        {
            icon: IconChartBar,
            moment: t`After the event`,
            title: t`Reliable figures, immediately`,
            text: t`Real-time attendee list, exportable attendance sheet, follow-up messages by e-mail and SMS.`,
        },
    ];

    const steps = [
        {
            number: "01",
            name: t`Invite`,
            title: t`Invite and register your attendees`,
            text: t`Create your event page, send your invitations and track replies. Every registrant receives a personal ticket with a unique QR code.`,
            items: [
                t`Registration page in your colors`,
                t`Custom questions (job title, dietary needs, workshop…)`,
                t`Free or paid tickets: your sales revenue is paid to you every Monday`,
            ],
            links: [],
            visual: <InvitationPanel label={t`Invitation tracking screen: 248 invitations sent, 173 registered, and the status of each guest.`}/>,
        },
        {
            number: "02",
            name: t({message: "Welcome", context: "Landing page step verb"}),
            links: [
                {to: "/controle-acces-qr-code", label: t`QR code access control`},
                {to: "/impression-badges-evenement", label: t`On-site badge printing`},
            ],
            title: t`Welcome attendees without waiting, badge in hand`,
            text: t`At the entrance, one scan of the QR code grants access and starts printing the badge. Unregistered attendees are signed up on the spot from a tablet.`,
            items: [
                t`QR code access control`,
                t`On-site badge printing`,
                t`On-site registration from a tablet`,
                t`Personalized QR code wristbands in your colors, with your logo`,
            ],
            visual: (
                <div className={classes.stepVisualDuo}>
                    <ScanScreen label={t`Welcome desk tablet showing “Access granted” for Camille Martin, with her badge printing.`}/>
                    <PrintedBadge
                        className={classes.stepBadge}
                        label={t`Printed name badge for Camille Martin, Speaker, with her QR code.`}
                    />
                </div>
            ),
        },
        {
            number: "03",
            name: t`Monitor`,
            links: [{to: "/emargement-numerique", label: t`Digital attendance sheet`}],
            title: t`Monitor attendance in real time`,
            text: t`Follow arrivals minute by minute and get a usable attendance sheet as soon as the event ends.`,
            items: [
                t`Real-time attendee list`,
                t`Attendance sheet and check-in report`,
                t`Messages to attendees (e-mail and/or SMS)`,
            ],
            visual: <LiveDashboard label={t`Live attendance dashboard: 412 registered, 367 present, 89% attendance rate, arrivals per half hour and attendance sheet export.`}/>,
        },
    ];

    const useCases = [
        {
            icon: IconBuildingSkyscraper,
            title: t`Companies`,
            description: t`Open days, seminars, internal events.`,
        },
        {
            icon: IconBuildingBank,
            title: t`Institutions and local authorities`,
            link: {to: "/logiciel-evenement-collectivites", label: t`Event software for local authorities`},
            description: t`Conferences, sporting events, general assemblies.`,
        },
        {
            icon: IconUsersGroup,
            title: t`Associations and professional networks`,
            description: t`Festivals, workshops, award ceremonies.`,
        },
    ];

    const offers = [
        {
            icon: IconHeadset,
            tag: t`Turnkey`,
            title: t`Support on the day`,
            text: t`Our connected staff handle your attendees' welcome, with the equipment installed and configured.`,
            items: [
                t`Event set-up with your teams`,
                t`Connected welcome staff on site`,
                t`Tablets, scanners and badge printers provided`,
                t`Attendance report after the event`,
            ],
        },
        {
            icon: IconDeviceTablet,
            tag: t`Self-service`,
            title: t`Equipment rental`,
            text: t`Your teams run the welcome with the platform; we rent you ready-to-use equipment.`,
            shop: true,
            link: {to: "/location-materiel-evenementiel", label: t`Learn more about equipment rental`},
            items: [
                t`Full access to the platform`,
                t`Rental of tablets, scanners and badge printers`,
                t`Free registrations at no cost, 0.99 € per paid ticket sold`,
            ],
        },
    ];

    const trustPoints = [
        {
            icon: IconServer,
            title: t`Hosted in France`,
            text: t`Data is stored on servers located in France (OVHcloud) and is never resold.`,
        },
        {
            icon: IconShieldCheck,
            title: t`GDPR compliant`,
            text: t`You remain the data controller; ${appName} acts as processor under article 28 of the GDPR.`,
        },
        {
            icon: IconLock,
            title: t`Secured access`,
            text: t`Encrypted connections (HTTPS), access restricted by role, daily backups.`,
        },
        {
            icon: IconClockHour4,
            title: t`Limited retention`,
            text: t`Data is kept only as long as needed for the event and legal obligations, then deleted.`,
        },
    ];

    const faq = [
        {
            question: t`How does the demo work?`,
            answer: t`After your request, we reply within 1 business day to schedule a time. We show you the platform based on your next event: invitations, welcome, badges and reporting.`,
        },
        {
            question: t`Do we need to install an application?`,
            answer: t`No. The platform works in the browser, on a computer, tablet or smartphone. For the welcome desk, we provide the equipment if needed.`,
        },
        {
            question: t`How are badges printed on the day?`,
            answer: t`From the welcome desk on a tablet: search for the attendee or scan their QR code, then print the badge and record the entry in one gesture.`,
        },
        {
            question: t`Can registrations be free?`,
            answer: t`Yes. Free registrations have no fees; the fee of 0.99 € only applies to paid tickets sold.`,
        },
        {
            question: t`Can we use the platform on our own, without support?`,
            answer: t`Yes. Your teams can run the welcome themselves and only rent the equipment you need.`,
        },
    ];

    return (
        <div className={classes.page}>
            <Helmet>
                <title>{pageTitle}</title>
                <meta name="description" content={pageDescription}/>
                <link rel="canonical" href={canonicalUrl}/>
                <meta property="og:type" content="website"/>
                <meta property="og:site_name" content={appName}/>
                <meta property="og:locale" content={toOpenGraphLocale(i18n.locale || "en")}/>
                <meta property="og:title" content={pageTitle}/>
                <meta property="og:description" content={pageDescription}/>
                <meta property="og:url" content={canonicalUrl}/>
                <meta property="og:image" content={`${frontendUrl}${SHARE_IMAGE_PATH}`}/>
                <meta property="og:image:width" content="1200"/>
                <meta property="og:image:height" content="630"/>
                <meta property="og:image:alt" content={pageTitle}/>
                <meta name="twitter:card" content="summary_large_image"/>
                <meta name="twitter:title" content={pageTitle}/>
                <meta name="twitter:description" content={pageDescription}/>
                <meta name="twitter:image" content={`${frontendUrl}${SHARE_IMAGE_PATH}`}/>
                <script type="application/ld+json">
                    {JSON.stringify(structuredData).replace(/</g, "\\u003c")}
                </script>
            </Helmet>

            <a href="#main" className={classes.skipLink}>{t`Skip to content`}</a>

            <MarketingHeader isLanding/>

            <main id="main">
                <section className={classes.hero} aria-labelledby="hero-title">
                    <p className={classes.heroBadge}>{appName}</p>
                    <h1 id="hero-title" className={classes.heroTitle}>
                        <Trans>A <mark>flawless</mark> event welcome, from the first registrant to the last badge.</Trans>
                    </h1>
                    <p className={classes.heroText}>
                        {t`Invitations, registrations, badges and QR code wristbands in your colors: ${appName} brings it all together. Your attendees get in without waiting, and you follow every arrival live.`}
                    </p>
                    <div ref={heroCtaRef} className={classes.heroActions}>
                        <a href={DEMO_ANCHOR} className={`${classes.primaryButton} ${classes.primaryButtonLarge}`}>
                            <IconCalendarEvent size={20} aria-hidden="true"/> {t`Schedule my demo`}
                        </a>
                        <a href="#how-it-works" className={classes.textLink}>
                            {t`See how it works`} <IconArrowRight size={18} aria-hidden="true"/>
                        </a>
                    </div>
                    <p className={classes.responseTime}>{t`Reply within 1 business day`}</p>
                    <ul className={classes.heroTrust} aria-label={t`Our commitments`}>
                        <li><IconServer size={18} aria-hidden="true"/>{t`Hosted in France`}</li>
                        <li><IconShieldCheck size={18} aria-hidden="true"/>{t`GDPR`}</li>
                        <li><IconHeadset size={18} aria-hidden="true"/>{t`Support on the day`}</li>
                    </ul>

                    <HeroVideo/>
                </section>

                <section className={`${classes.section} ${classes.sectionMuted}`} aria-labelledby="benefits-title">
                    <div className={classes.container}>
                        <p className={classes.eyebrow}>{t`Before, during and after your event`}</p>
                        <h2 id="benefits-title" className={classes.sectionTitle}>
                            {t`You welcome your guests, we take care of the rest`}
                        </h2>
                        <div className={classes.benefits}>
                            {benefits.map((benefit) => (
                                <article key={benefit.moment} className={classes.benefit}>
                                    <benefit.icon size={28} stroke={1.6} className={classes.icon} aria-hidden="true"/>
                                    <p className={classes.moment}>{benefit.moment}</p>
                                    <h3 className={classes.cardTitle}>{benefit.title}</h3>
                                    <p className={classes.cardText}>{benefit.text}</p>
                                </article>
                            ))}
                        </div>
                    </div>
                </section>

                <section id="how-it-works" className={classes.section} aria-labelledby="how-title">
                    <div className={classes.container}>
                        <p className={classes.eyebrow}>{t`How it works`}</p>
                        <h2 id="how-title" className={classes.sectionTitle}>
                            {t`Invite, welcome, monitor: your event in 3 steps`}
                        </h2>
                        <ol className={classes.steps}>
                            {steps.map((step) => (
                                <li key={step.number} className={classes.step}>
                                    <div className={classes.stepContent}>
                                        <p className={classes.stepNumber}>
                                            <span aria-hidden="true">{step.number}</span> {step.name}
                                        </p>
                                        <h3 className={classes.stepTitle}>{step.title}</h3>
                                        <p className={classes.stepText}>{step.text}</p>
                                        <ul className={classes.checkList}>
                                            {step.items.map((item) => (
                                                <li key={item}>
                                                    <IconCheck size={18} stroke={2.2} aria-hidden="true"/>{item}
                                                </li>
                                            ))}
                                        </ul>
                                        {step.links.length > 0 && (
                                            <p className={classes.solutionLinks}>
                                                {step.links.map((link) => (
                                                    <Link key={link.to} to={link.to}>
                                                        {link.label} <IconArrowRight size={16} aria-hidden="true"/>
                                                    </Link>
                                                ))}
                                            </p>
                                        )}
                                    </div>
                                    <div className={classes.stepVisual}>{step.visual}</div>
                                </li>
                            ))}
                        </ol>
                        <p className={classes.visualNote}>{t`Interface visuals shown with sample data.`}</p>
                    </div>
                </section>

                <section id="use-cases" className={`${classes.section} ${classes.sectionMuted}`} aria-labelledby="use-cases-title">
                    <div className={classes.container}>
                        <p className={classes.eyebrow}>{t`Use cases`}</p>
                        <h2 id="use-cases-title" className={classes.sectionTitle}>
                            {t`A welcome solution for companies, institutions and networks`}
                        </h2>
                        <div className={classes.cards}>
                            {useCases.map((useCase) => (
                                <article key={useCase.title} className={classes.card}>
                                    <useCase.icon size={28} stroke={1.6} className={classes.icon} aria-hidden="true"/>
                                    <h3 className={classes.cardTitle}>{useCase.title}</h3>
                                    <p className={classes.cardText}>{useCase.description}</p>
                                    {"link" in useCase && useCase.link && (
                                        <p className={classes.solutionLinks}>
                                            <Link to={useCase.link.to}>
                                                {useCase.link.label} <IconArrowRight size={16} aria-hidden="true"/>
                                            </Link>
                                        </p>
                                    )}
                                </article>
                            ))}
                        </div>
                    </div>
                </section>

                {hasCaseStudies && (
                    <section id="case-studies" className={classes.section} aria-labelledby="case-studies-title">
                        <div className={classes.container}>
                            <p className={classes.eyebrow}>{t`Case studies`}</p>
                            <h2 id="case-studies-title" className={classes.sectionTitle}>
                                {t`They trusted us with their events`}
                            </h2>
                            <div className={classes.cards}>
                                {featuredCaseStudies.map((caseStudy) => (
                                    <CaseStudyCard key={caseStudy.slug} caseStudy={caseStudy}/>
                                ))}
                            </div>
                            <p className={classes.centerLink}>
                                <Link to="/cas-clients">{t`See all case studies`}</Link>
                            </p>
                        </div>
                    </section>
                )}

                <section id="offers" className={sectionTone(true)} aria-labelledby="offers-title">
                    <div className={classes.container}>
                        <p className={classes.eyebrow}>{t`Our offers`}</p>
                        <h2 id="offers-title" className={classes.sectionTitle}>
                            {t`Support on the day or self-service: choose your formula`}
                        </h2>
                        <div className={classes.offers}>
                            {offers.map((offer) => (
                                <article key={offer.title} className={classes.offer}>
                                    <div className={classes.offerHead}>
                                        <offer.icon size={28} stroke={1.6} className={classes.icon} aria-hidden="true"/>
                                        <span className={classes.offerTag}>{offer.tag}</span>
                                    </div>
                                    <h3 className={classes.offerTitle}>{offer.title}</h3>
                                    <p className={classes.cardText}>{offer.text}</p>
                                    <ul className={classes.checkList}>
                                        {offer.items.map((item) => (
                                            <li key={item}>
                                                <IconCheck size={18} stroke={2.2} aria-hidden="true"/>{item}
                                            </li>
                                        ))}
                                    </ul>
                                    <div className={classes.offerLinks}>
                                        <a href={DEMO_ANCHOR} className={classes.textLink}>
                                            {t`Schedule my demo`} <IconArrowRight size={18} aria-hidden="true"/>
                                        </a>
                                        {offer.shop && (
                                            <a href={shopUrl} className={classes.textLink}>
                                                <IconShoppingBag size={18} aria-hidden="true"/> {t`Rent equipment online`}
                                            </a>
                                        )}
                                        {"link" in offer && offer.link && (
                                            <Link to={offer.link.to} className={classes.textLink}>
                                                {offer.link.label} <IconArrowRight size={18} aria-hidden="true"/>
                                            </Link>
                                        )}
                                    </div>
                                </article>
                            ))}
                        </div>
                        <p className={classes.registerNote}>
                            {t`Prefer to get started on your own?`}{" "}
                            <Link to="/auth/register">{t`Create an account`}</Link>
                        </p>
                    </div>
                </section>

                <section className={sectionTone(false)} aria-labelledby="trust-title">
                    <div className={classes.container}>
                        <p className={classes.eyebrow}>{t`Trust`}</p>
                        <h2 id="trust-title" className={classes.sectionTitle}>
                            {t`Data hosted in France, GDPR compliance and security`}
                        </h2>
                        <div className={classes.trust}>
                            {trustPoints.map((point) => (
                                <article key={point.title} className={classes.trustItem}>
                                    <point.icon size={26} stroke={1.6} className={classes.icon} aria-hidden="true"/>
                                    <h3 className={classes.trustTitle}>{point.title}</h3>
                                    <p className={classes.cardText}>{point.text}</p>
                                </article>
                            ))}
                        </div>
                        <p className={classes.centerLink}>
                            <a href={getPrivacyPolicyUrl()}>{t`Read our privacy policy`}</a>
                        </p>
                    </div>
                </section>

                <section id="faq" className={sectionTone(true)} aria-labelledby="faq-title">
                    <div className={classes.containerNarrow}>
                        <h2 id="faq-title" className={classes.sectionTitle}>{t`Frequently asked questions`}</h2>
                        <div className={classes.faq}>
                            {faq.map((entry) => (
                                <details key={entry.question} className={classes.faqItem}>
                                    <summary>{entry.question}</summary>
                                    <p>{entry.answer}</p>
                                </details>
                            ))}
                        </div>
                    </div>
                </section>

                <MarketingDemoSection
                    ref={demoSectionRef}
                    title={t`Schedule your ${appName} demo`}
                    text={t`Tell us about your next event: we will show you, on your own case, the complete journey from invitation to attendance report.`}
                />

                <section className={classes.attendee} aria-labelledby="attendee-title">
                    <h2 id="attendee-title">{t`You registered for an event?`}</h2>
                    <p>{t`Your ticket was sent by e-mail. Lost it? Find it from the login page with your e-mail address.`}</p>
                    <Link to="/auth/login" className={classes.textLink}>
                        {t`Find my tickets`} <IconArrowRight size={18} aria-hidden="true"/>
                    </Link>
                </section>
            </main>

            <MarketingFooter/>

            <div className={`${classes.stickyCta} ${showStickyCta ? classes.stickyCtaVisible : ""}`} aria-hidden={!showStickyCta}>
                <a href={DEMO_ANCHOR} className={classes.primaryButton} tabIndex={showStickyCta ? 0 : -1}>
                    {t`Schedule my demo`}
                </a>
            </div>
        </div>
    );
};

export default Landing;
