import {useEffect, useId, useRef, useState} from "react";
import {t} from "@lingui/macro";
import classNames from "classnames";
import {UserGeneratedContent} from "../../../common/UserGeneratedContent";
import classes from "./EmbeddedRegistration.module.scss";

const hasVisibleContent = (html?: string | null): boolean =>
    !!html && (/<img\b/i.test(html) || html.replace(/<[^>]*>/g, '').replace(/&nbsp;/gi, ' ').trim().length > 0);

export const LineDescription = ({html}: { html?: string | null }) => {
    const contentRef = useRef<HTMLDivElement>(null);
    const [expanded, setExpanded] = useState(false);
    const [isClamped, setIsClamped] = useState(false);
    const contentId = useId();

    useEffect(() => {
        const element = contentRef.current?.firstElementChild as HTMLElement | null;
        if (!element || expanded) {
            return;
        }
        const measure = () => setIsClamped(element.scrollHeight > element.clientHeight + 1);
        measure();
        if (typeof ResizeObserver === 'undefined') {
            return;
        }
        const observer = new ResizeObserver(measure);
        observer.observe(element);
        return () => observer.disconnect();
    }, [html, expanded]);

    if (!hasVisibleContent(html)) {
        return null;
    }

    return (
        <div className={classes.lineDescription}>
            <div ref={contentRef}>
                <UserGeneratedContent
                    id={contentId}
                    className={classNames(classes.lineDescriptionText, !expanded && classes.lineDescriptionClamped)}
                    dangerouslySetInnerHTML={{__html: html as string}}
                />
            </div>
            {(isClamped || expanded) && (
                <button
                    type="button"
                    className={classes.lineDescriptionToggle}
                    aria-expanded={expanded}
                    aria-controls={contentId}
                    onClick={() => setExpanded(value => !value)}
                >
                    {expanded ? t`See less` : t`See more`}
                </button>
            )}
        </div>
    );
};
