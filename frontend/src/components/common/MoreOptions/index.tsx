import {ReactNode} from "react";
import {Collapse, UnstyledButton} from "@mantine/core";
import {useDisclosure} from "@mantine/hooks";
import {IconChevronDown, IconChevronUp} from "@tabler/icons-react";
import classes from "./MoreOptions.module.scss";

export const MoreOptions = ({label, children, defaultOpened = false}: { label: string, children: ReactNode, defaultOpened?: boolean }) => {
    const [opened, {toggle}] = useDisclosure(defaultOpened);

    return (
        <div className={classes.wrapper}>
            <UnstyledButton type="button" className={classes.toggle} onClick={toggle} aria-expanded={opened}>
                {opened ? <IconChevronUp size={16}/> : <IconChevronDown size={16}/>}
                {label}
            </UnstyledButton>
            <Collapse in={opened}>
                <div className={classes.content}>{children}</div>
            </Collapse>
        </div>
    );
};
