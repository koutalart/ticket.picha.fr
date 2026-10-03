import {forwardRef} from "react";
import {t} from "@lingui/macro";
import {IconCheck} from "@tabler/icons-react";
import {DemoRequestForm} from "../DemoRequestForm";
import classes from "./MarketingDemoSection.module.scss";

const CONTACT_EMAIL = "ticket@picha.fr";

interface MarketingDemoSectionProps {
    title: string;
    text: string;
}

export const MarketingDemoSection = forwardRef<HTMLElement, MarketingDemoSectionProps>(({title, text}, ref) => (
    <section id="demo" ref={ref} className={classes.demo} aria-labelledby="demo-title">
        <div className={classes.inner}>
            <div>
                <h2 id="demo-title" className={classes.title}>{title}</h2>
                <p className={classes.text}>{text}</p>
                <ul className={classes.promises}>
                    <li><IconCheck size={20} stroke={2.2} aria-hidden="true"/>{t`Reply within 1 business day`}</li>
                    <li><IconCheck size={20} stroke={2.2} aria-hidden="true"/>{t`Demo tailored to your event`}</li>
                    <li><IconCheck size={20} stroke={2.2} aria-hidden="true"/>{t`No commitment`}</li>
                </ul>
                <p className={classes.contact}>
                    {t`Prefer e-mail?`} <a href={`mailto:${CONTACT_EMAIL}`}>{CONTACT_EMAIL}</a>
                </p>
            </div>
            <div className={classes.card}>
                <DemoRequestForm/>
            </div>
        </div>
    </section>
));
