import {t} from "@lingui/macro";
import classes from "./FloatingPoweredBy.module.scss";
import classNames from "classnames";
import React from "react";
import {getAppName, getPlatformUrl, getPrivacyPolicyUrl, getTermsOfSaleUrl, getTermsOfUseUrl} from "../../../utilites/branding.ts";

export const PoweredByFooter = (
    props: React.DetailedHTMLProps<React.HTMLAttributes<HTMLDivElement>, HTMLDivElement>
) => {
    return (
        <div {...props} className={classNames(classes.poweredBy, props.className)}>
            <div className={classes.poweredByText}>
                {t`Powered by`}{" "}
                <a href={getPlatformUrl()} target="_blank" rel="noreferrer">
                    {getAppName()}
                </a>
            </div>
            <div className={classes.legalLinks}>
                <a href="/legal/mentions-legales">{t`Legal notice`}</a>
                <a href={getTermsOfUseUrl()}>{t`Terms of use`}</a>
                <a href={getTermsOfSaleUrl()}>{t`Terms of sale`}</a>
                <a href={getPrivacyPolicyUrl()}>{t`Privacy`}</a>
                <a href="/legal/cookies">{t`Cookies`}</a>
            </div>
        </div>
    );
}
