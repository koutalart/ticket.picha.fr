import {Link} from "react-router";
import {t} from "@lingui/macro";
import {PoweredByFooter} from "../../../common/PoweredByFooter";
import {getConfig} from "../../../../utilites/config.ts";
import {getVisibleCaseStudies} from "../../case-studies/caseStudies.ts";
import {SolutionSlug, solutionPath} from "../../solutions/solutions.ts";
import classes from "./MarketingFooter.module.scss";

export const MarketingFooter = () => {
    const shopUrl = getConfig("VITE_SHOP_URL") || "/location-materiel-evenementiel";
    const solutionLinks: { slug: SolutionSlug; label: string }[] = [
        {slug: "controle-acces-qr-code", label: t`QR code access control`},
        {slug: "emargement-numerique", label: t`Digital attendance sheet`},
        {slug: "impression-badges-evenement", label: t`On-site badge printing`},
        {slug: "logiciel-evenement-collectivites", label: t`Event software for local authorities`},
        {slug: "location-materiel-evenementiel", label: t`Equipment rental`},
    ];

    return (
        <footer className={classes.footer}>
            <div className={classes.columns}>
                <nav aria-labelledby="footer-solutions">
                    <h2 id="footer-solutions" className={classes.heading}>{t`Solutions`}</h2>
                    <ul>
                        {solutionLinks.map((link) => (
                            <li key={link.slug}><Link to={solutionPath(link.slug)}>{link.label}</Link></li>
                        ))}
                    </ul>
                </nav>
                <nav aria-labelledby="footer-picha">
                    <h2 id="footer-picha" className={classes.heading}>PICHA Ticket</h2>
                    <ul>
                        {getVisibleCaseStudies().length > 0 && <li><Link to="/cas-clients">{t`Case studies`}</Link></li>}
                        <li><a href={shopUrl}>{t`Shop`}</a></li>
                        <li><a href="/#demo">{t`Schedule my demo`}</a></li>
                        <li><Link to="/auth/login">{t`Log in`}</Link></li>
                    </ul>
                </nav>
            </div>
            <PoweredByFooter className={classes.legal}/>
        </footer>
    );
};
