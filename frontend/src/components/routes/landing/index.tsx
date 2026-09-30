import {Link} from "react-router";
import {Helmet} from "react-helmet-async";
import {t} from "@lingui/macro";
import {
    IconBuildingBank,
    IconBuildingSkyscraper,
    IconCheck,
    IconClipboardCheck,
    IconId,
    IconLock,
    IconUsersGroup,
} from "@tabler/icons-react";
import {PoweredByFooter} from "../../common/PoweredByFooter";
import {getAppName, getLogoForLightBackground} from "../../../utilites/branding.ts";
import classes from "./Landing.module.scss";

const CONTACT_EMAIL = "ticket@picha.fr";

const Landing = () => {
    const audiences = [
        {
            icon: IconBuildingSkyscraper,
            title: t`Companies`,
            description: t`Seminars, client evenings, partner days, internal events.`,
        },
        {
            icon: IconBuildingBank,
            title: t`Institutions and local authorities`,
            description: t`Conferences, public meetings, general assemblies, forums.`,
        },
        {
            icon: IconUsersGroup,
            title: t`Associations and professional networks`,
            description: t`Networking events, workshops, trade fairs, award ceremonies.`,
        },
    ];

    const eventTypes = [
        t`Conferences`,
        t`Seminars`,
        t`General assemblies`,
        t`Forums and trade fairs`,
        t`Workshops and training`,
        t`Award ceremonies`,
        t`Client evenings`,
        t`Open days`,
    ];

    const pillars = [
        {
            icon: IconClipboardCheck,
            title: t`Registration and ticketing`,
            items: [
                t`Event page with online registration`,
                t`Free or paid tickets`,
                t`Custom registration questions`,
                t`Capacity limits and waiting list`,
            ],
        },
        {
            icon: IconId,
            title: t`Welcome and access control`,
            items: [
                t`Unique QR code for each attendee`,
                t`Badges printed on arrival`,
                t`On-site registration from a tablet`,
                t`Entry check with a smartphone or scanner`,
            ],
        },
        {
            icon: IconCheck,
            title: t`Follow-up and reporting`,
            items: [
                t`Real-time attendee list`,
                t`Attendance sheet and check-in report`,
                t`Messages to attendees`,
                t`Exports for your follow-up`,
            ],
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
                    <p className={classes.kicker}>{t`Professional and institutional events`}</p>
                    <h1 className={classes.heroTitle}>{t`Registration, badges and access control for your events`}</h1>
                    <p className={classes.heroText}>
                        {t`${getAppName()} manages your registrations, your welcome desk and your attendance sheets, from invitation to the final report.`}
                    </p>
                    <div className={classes.heroActions}>
                        <a href={`mailto:${CONTACT_EMAIL}`} className={classes.primaryButton}>{t`Request a demo`}</a>
                        <Link to="/auth/register" className={classes.secondaryButton}>{t`Create an account`}</Link>
                    </div>
                </section>

                <section className={classes.section}>
                    <h2 className={classes.sectionTitle}>{t`For all organizations`}</h2>
                    <div className={classes.cards}>
                        {audiences.map((audience) => (
                            <article key={audience.title} className={classes.card}>
                                <audience.icon size={28} className={classes.cardIcon}/>
                                <h3 className={classes.cardTitle}>{audience.title}</h3>
                                <p className={classes.cardText}>{audience.description}</p>
                            </article>
                        ))}
                    </div>
                    <ul className={classes.chips}>
                        {eventTypes.map((eventType) => <li key={eventType}>{eventType}</li>)}
                    </ul>
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
                    <div className={classes.trust}>
                        <IconLock size={32} className={classes.cardIcon}/>
                        <div>
                            <h2 className={classes.trustTitle}>{t`Your data, protected`}</h2>
                            <p className={classes.cardText}>
                                {t`Hosting in France, encrypted connections, access restricted by role and GDPR compliance. Simple pricing: 0.99 € per paid ticket sold.`}
                            </p>
                        </div>
                    </div>
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
