import classes from "./SectionTitle.module.scss";

interface SectionTitleProps {
    eyebrow?: string;
    title: string;
    subtitle?: string;
}

export const SectionTitle = ({ eyebrow, title, subtitle }: SectionTitleProps) => {
    return (
        <div className={classes.wrapper}>
            {eyebrow && <div className={classes.eyebrow}>{eyebrow}</div>}
            <h2 className={classes.title}>{title}</h2>
            {subtitle && <p className={classes.subtitle}>{subtitle}</p>}
        </div>
    );
};
