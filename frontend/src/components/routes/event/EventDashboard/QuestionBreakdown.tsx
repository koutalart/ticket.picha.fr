import {Skeleton} from "@mantine/core";
import {plural, t, Trans} from "@lingui/macro";
import {i18n} from "@lingui/core";
import {useGetQuestionAnswerDistribution} from "../../../../queries/useGetQuestionAnswerDistribution.ts";
import {IdParam, QuestionAnswerDistribution} from "../../../../types.ts";
import {formatNumber} from "../../../../utilites/helpers.ts";
import classes from "./QuestionBreakdown.module.scss";

const MULTIPLE_CHOICE_TYPES = ['CHECKBOX', 'MULTI_SELECT_DROPDOWN'];

const formatPercentage = (value: number) => `${new Intl.NumberFormat(i18n.locale, {maximumFractionDigits: 1}).format(value)} %`;

const QuestionCard = ({question}: { question: QuestionAnswerDistribution }) => {
    const answered = question.options.filter((option) => option.count > 0);
    const unanswered = question.options.filter((option) => option.count === 0);
    const isMultiple = MULTIPLE_CHOICE_TYPES.includes(question.type);

    return (
        <div className={classes.card}>
            <div className={classes.cardHead}>
                <h3 className={classes.questionTitle}>{question.title}</h3>
                <span className={classes.responses}>
                    {plural(question.total_responses, {one: '# response', other: '# responses'})}
                </span>
            </div>
            <p className={classes.scope}>
                {question.belongs_to === 'ORDER' ? t`One answer per order` : t`One answer per attendee`}
                {isMultiple && <>, {t`several choices possible`}</>}
            </p>

            {answered.length === 0 && <p className={classes.muted}><Trans>No answers yet.</Trans></p>}

            <ul className={classes.options}>
                {answered.map((option) => (
                    <li key={option.label} className={classes.option}>
                        <div className={classes.optionHead}>
                            <span className={classes.optionLabel}>{option.label}</span>
                            <span className={classes.optionCount}>
                                {formatNumber(option.count)}
                                <span className={classes.optionPercent}>{formatPercentage(option.percentage)}</span>
                            </span>
                        </div>
                        <div className={classes.bar}>
                            <div style={{width: `${Math.min(100, option.percentage)}%`}}/>
                        </div>
                    </li>
                ))}
            </ul>

            {answered.length > 0 && unanswered.length > 0 && (
                <p className={classes.unanswered}>
                    <Trans>Not chosen:</Trans> {unanswered.map((option) => option.label).join(', ')}
                </p>
            )}
        </div>
    );
};

export const QuestionBreakdown = ({eventId}: { eventId: IdParam }) => {
    const {data: questions, isLoading} = useGetQuestionAnswerDistribution(eventId);

    if (isLoading) {
        return (
            <section className={classes.section}>
                <Skeleton height={24} width={240} mb="md"/>
                <Skeleton height={160}/>
            </section>
        );
    }

    if (!questions || questions.length === 0) {
        return null;
    }

    return (
        <section className={classes.section}>
            <h2 className={classes.sectionTitle}><Trans>Attendee breakdown</Trans></h2>
            <p className={classes.intro}>
                <Trans>Answers to the multiple choice questions, for valid orders and attendees only.</Trans>
            </p>
            <div className={classes.grid}>
                {questions.map((question) => (
                    <QuestionCard key={question.question_id} question={question}/>
                ))}
            </div>
        </section>
    );
};
