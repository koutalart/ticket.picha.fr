import {Link} from "react-router";
import {Helmet} from "react-helmet-async";
import {t} from "@lingui/macro";
import {
    IconBuildingBank,
    IconBuildingSkyscraper,
    IconCheck,
    IconClipboardCheck,
    IconId,
    IconClockHour4,
    IconFileCertificate,
    IconLock,
    IconServer,
    IconShieldCheck,
    IconUserCheck,
    IconUsersGroup,
} from "@tabler/icons-react";
import {PoweredByFooter} from "../../common/PoweredByFooter";
import {getAppName, getLogoForLightBackground, getPrivacyPolicyUrl} from "../../../utilites/branding.ts";
import classes from "./Landing.module.scss";

const CONTACT_EMAIL = "ticket@picha.fr";

const Landing = () => {
    const audiences = [
        {
            icon: IconBuildingSkyscraper,
            title: t`Companies`,
            description: t`Open days, seminars, internal events.`,
        },
        {
            icon: IconBuildingBank,
            title: t`Institutions and local authorities`,
            description: t`Conferences, sporting events, general assemblies.`,
        },
        {
            icon: IconUsersGroup,
            title: t`Associations and professional networks`,
            description: t`Festivals, workshops, award ceremonies.`,
        },
    ];

    const pillars = [
        {
            icon: IconClipboardCheck,
            title: t`Registration and ticketing`,
            items: [
                t`Event page with custom questions during online registration`,
                t`Free or paid tickets`,
                t`Physical pre-sales and personalized wristbands`,
            ],
        },
        {
            icon: IconId,
            title: t`Welcome and access control`,
            items: [
                t`Unique QR code for each attendee`,
                t`Badges printed on arrival`,
                t`On-site registration from a tablet`,
                t`Entry check with a scanner`,
            ],
        },
        {
            icon: IconCheck,
            title: t`Follow-up and reporting`,
            items: [
                t`Real-time attendee list`,
                t`Attendance sheet and check-in report`,
                t`Messages to attendees (e-mail and/or SMS)`,
                t`Payout every Monday`,
            ],
        },
    ];

    const gdprPoints = [
        {
            icon: IconServer,
            title: t`Hosted in France`,
            description: t`Data is stored on servers located in France (OVHcloud) and is never resold.`,
        },
        {
            icon: IconLock,
            title: t`Secured access`,
            description: t`Encrypted connections (HTTPS), access restricted by role, daily backups.`,
        },
        {
            icon: IconShieldCheck,
            title: t`Clear roles`,
            description: t`The organizer is the data controller for its attendees; ${getAppName()} acts as processor under article 28 of the GDPR.`,
        },
        {
            icon: IconUserCheck,
            title: t`Attendees' rights`,
            description: t`Access, rectification, erasure and objection: requests are handled at ${CONTACT_EMAIL}.`,
        },
        {
            icon: IconClockHour4,
            title: t`Limited retention`,
            description: t`Data is kept only as long as needed for the event and legal obligations, then deleted.`,
        },
        {
            icon: IconFileCertificate,
            title: t`Consent`,
            description: t`Marketing opt-in and non-essential cookies are only activated with the person's consent.`,
        },
    ];

    const faq = [
        {
            question: t`Can registrations be free?`,
            answer: t`Yes. Free registrations have no fees; the fee of 0.99 € only applies to paid tickets sold.`,
        },
        {
            question: t`How are badges printed on the day?`,
            answer: t`From the welcome desk on a tablet: search for the attendee or scan their QR code, then print the badge and record the entry in one gesture.`,
        },
        {
            question: t`Where is the data hosted?`,
            answer: t`On servers located in France. Each organizer only accesses the data of its own events.`,
        },
    ];

    return (
        <div className={classes.page}>
            <Helmet>
                <title>{`${getAppName()} — ${t`Event management for organizations`}`}</title>
            </Helmet>

            <header className={classes.header}>
                <Link to="/" className={classes.logoLink}>
                    <img src={getLogoForLightBackground()} alt={getAppName()} className={classes.logo}/>
                </Link>
                <nav className={classes.headerActions}>
                    <Link to="/auth/login" className={classes.linkButton}>{t`Log in`}</Link>
                    <a href={`mailto:${CONTACT_EMAIL}`} className={classes.primaryButton}>{t`Request a demo`}</a>
                </nav>
            </header>

            <main>
                <section className={classes.hero}>
                    <h1 className={classes.heroTitle}>{t`Professional and institutional events`}</h1>
                    <p className={classes.heroText}>
                        {t`${getAppName()} handles sending your invitations, digital check-in with connected staff, on-site badge printing and post-event reporting.`}
                    </p>
                    <p className={classes.heroText}>
                        {t`Prefer to stay independent? Simply rent the equipment.`}
                    </p>
                    <div className={classes.heroActions}>
                        <a href={`mailto:${CONTACT_EMAIL}`} className={classes.primaryButton}>{t`Request a demo`}</a>
                        <Link to="/auth/register" className={classes.secondaryButton}>{t`Create an account`}</Link>
                    </div>
                </section>

                <section className={classes.section}>
                    <h2 className={classes.sectionTitle}>{t`For organized people`}</h2>
                    <div className={classes.cards}>
                        {audiences.map((audience) => (
                            <article key={audience.title} className={classes.card}>
                                <audience.icon size={28} className={classes.cardIcon}/>
                                <h3 className={classes.cardTitle}>{audience.title}</h3>
                                <p className={classes.cardText}>{audience.description}</p>
                            </article>
                        ))}
                    </div>
                </section>

                <section className={`${classes.section} ${classes.sectionTinted}`}>
                    <h2 className={classes.sectionTitle}>{t`One platform, from registration to attendance sheet`}</h2>
                    <div className={classes.cards}>
                        {pillars.map((pillar) => (
                            <article key={pillar.title} className={classes.pillar}>
                                <pillar.icon size={28} className={classes.cardIcon}/>
                                <h3 className={classes.cardTitle}>{pillar.title}</h3>
                                <ul className={classes.pillarList}>
                                    {pillar.items.map((item) => <li key={item}>{item}</li>)}
                                </ul>
                            </article>
                        ))}
                    </div>
                </section>

                <section className={classes.section}>
                    <h2 className={classes.sectionTitle}>{t`Your data and the GDPR`}</h2>
                    <div className={classes.cards}>
                        {gdprPoints.map((point) => (
                            <article key={point.title} className={classes.card}>
                                <point.icon size={28} className={classes.cardIcon}/>
                                <h3 className={classes.cardTitle}>{point.title}</h3>
                                <p className={classes.cardText}>{point.description}</p>
                            </article>
                        ))}
                    </div>
                    <p className={classes.gdprLink}>
                        <a href={getPrivacyPolicyUrl()}>{t`Read our privacy policy`}</a>
                    </p>
                </section>

                <section className={classes.section}>
                    <h2 className={classes.sectionTitle}>{t`Frequently asked questions`}</h2>
                    <div className={classes.faq}>
                        {faq.map((entry) => (
                            <details key={entry.question} className={classes.faqItem}>
                                <summary>{entry.question}</summary>
                                <p>{entry.answer}</p>
                            </details>
                        ))}
                    </div>
                </section>

                <section className={classes.attendee}>
                    <h2>{t`You registered for an event?`}</h2>
                    <p>{t`Your ticket was sent by e-mail. Lost it? Find it from the login page with your e-mail address.`}</p>
                    <Link to="/auth/login" className={classes.secondaryButton}>{t`Find my tickets`}</Link>
                </section>
            </main>

            <PoweredByFooter className={classes.footer}/>
        </div>
    );
};

export default Landing;
