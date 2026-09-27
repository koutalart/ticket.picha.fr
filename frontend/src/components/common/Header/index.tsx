import {Container} from '@mantine/core';
import classes from './Header.module.scss';
import {NavLink} from "react-router";
import {getAppName, getLogoForDarkBackground} from "../../../utilites/branding.ts";

interface HeaderProps {
    rightContent?: React.ReactNode;
    fullWidth?: boolean;
}

export const Header = ({rightContent, fullWidth = false}: HeaderProps) => {
    return (
        <header className={classes.header}>
            <Container size="md" className={classes.inner} fluid={fullWidth}>
                <NavLink className={classes.logo} to={'/manage/events'}>
                    <img src={getLogoForDarkBackground()} alt={`${getAppName()} logo`} className={classes.logo}/>
                </NavLink>

                <div className={classes.rightContent}>
                    {rightContent}
                </div>
            </Container>
        </header>
    );
}
