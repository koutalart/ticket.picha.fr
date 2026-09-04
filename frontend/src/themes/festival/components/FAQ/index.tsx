import { useState } from "react";
import classes from "./FAQ.module.scss";
import classNames from "classnames";
import { SectionTitle } from "../SectionTitle";
import { FaqItem } from "../../types/festivalTheme.types";

interface FAQProps {
    items: FaqItem[];
}

export const FAQ = ({ items }: FAQProps) => {
    const [openId, setOpenId] = useState<string | null>(null);

    return (
        <div className={classes.section}>
            <SectionTitle eyebrow="Questions fréquentes" title="FAQ" />
            {items.map((item) => (
                <div
                    key={item.id}
                    className={classNames(classes.item, { [classes.open]: openId === item.id })}
                    onClick={() => setOpenId(openId === item.id ? null : item.id)}
                >
                    <div className={classes.question}>
                        <span>{item.question}</span>
                        <span className={classes.arrow}>▾</span>
                    </div>
                    <div className={classes.answer}>{item.answer}</div>
                </div>
            ))}
        </div>
    );
};
