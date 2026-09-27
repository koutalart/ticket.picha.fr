/* eslint-disable lingui/no-unlocalized-strings */
import {Link, useParams} from "react-router";
import {Helmet} from "react-helmet-async";
import {getAppName, getLogoForLightBackground} from "../../../utilites/branding.ts";
import {legalDocuments, LegalSlug} from "./documents.tsx";
import {legalEntity} from "./legalEntity.ts";
import classes from "./Legal.module.scss";

const LegalPage = () => {
    const {slug} = useParams();
    const document = legalDocuments[slug as LegalSlug] ?? legalDocuments["mentions-legales"];

    return (
        <div className={classes.page}>
            <Helmet>
                <title>{`${document.title} | ${getAppName()}`}</title>
            </Helmet>
            <header className={classes.header}>
                <Link to="/">
                    <img src={getLogoForLightBackground()} alt={getAppName()} className={classes.logo}/>
                </Link>
            </header>
            <main className={classes.content}>
                <h1>{document.title}</h1>
                <p className={classes.updated}>Dernière mise à jour : {legalEntity.lastUpdated}</p>
                {document.content}
            </main>
            <nav className={classes.nav}>
                {Object.values(legalDocuments).map((doc) => (
                    <Link
                        key={doc.slug}
                        to={`/legal/${doc.slug}`}
                        className={doc.slug === document.slug ? classes.active : undefined}
                    >
                        {doc.title}
                    </Link>
                ))}
            </nav>
        </div>
    );
};

export default LegalPage;
