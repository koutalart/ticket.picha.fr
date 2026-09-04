import classes from "./Button.module.scss";
import classNames from "classnames";
import { ButtonHTMLAttributes } from "react";

interface FestivalButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: "primary" | "secondary";
}

export const Button = ({ variant = "primary", className, children, ...rest }: FestivalButtonProps) => {
    return (
        <button
            className={classNames(classes.button, classes[variant], className)}
            {...rest}
        >
            {children}
        </button>
    );
};
