<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Question;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\Enums\EventAssistantFactSource;
use HiEvents\DomainObjects\Enums\LiveChatAuthorType;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\LiveChatQuestionDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\LiveChatSessionDomainObjectAbstract;
use HiEvents\DomainObjects\LiveChatQuestionDomainObject;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\LiveChatQuestionStatus;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Mail\LiveChat\LiveChatAnswerMail;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatQuestionRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatSessionRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\LiveChat\Knowledge\EventAssistantKnowledgeService;
use HiEvents\Services\Domain\LiveChat\LiveChatReplyGenerator;
use HiEvents\Services\Domain\LiveChat\LiveChatService;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Contracts\Mail\Mailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Brings the organizer's answer back to the visitor: in the chat (shown at once if the page is still open), by SMS or
 * e-mail when the visitor left contact details, and records it as a confirmed fact of the event for the next visitors.
 */
class LiveChatAnswerRelayService
{
    public function __construct(
        private readonly LiveChatQuestionRepositoryInterface $questionRepository,
        private readonly LiveChatSessionRepositoryInterface $sessionRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly LiveChatService $liveChatService,
        private readonly LiveChatReplyGenerator $generator,
        private readonly EventAssistantKnowledgeService $knowledgeService,
        private readonly SmsSendService $sendService,
        private readonly CustomerLocaleResolver $localeResolver,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    public function relay(int $questionId): bool
    {
        /** @var LiveChatQuestionDomainObject|null $question */
        $question = $this->questionRepository->findFirstWhere([LiveChatQuestionDomainObjectAbstract::ID => $questionId]);
        if ($question === null || $question->getStatus() !== LiveChatQuestionStatus::ANSWERED->name || trim((string) $question->getAnswer()) === '') {
            return false;
        }

        /** @var OrganizerDomainObject $organizer */
        $organizer = $this->organizerRepository->findById($question->getOrganizerId());
        $event = $this->event($question);
        $tutoiement = $this->localeResolver->addressFormForOrganizerId($question->getOrganizerId()) === CustomerAddressForm::TU;

        $relayed = $this->generator->relayAnswer(
            organizerId: $question->getOrganizerId(),
            sessionId: $question->getLiveChatSessionId(),
            organizerName: $organizer->getName(),
            eventTitle: $event?->getTitle(),
            question: $question->getQuestion(),
            answer: (string) $question->getAnswer(),
            tutoiement: $tutoiement,
        );

        $session = $this->session($question);
        if ($session !== null) {
            foreach ($relayed->messages as $bubble) {
                try {
                    $this->liveChatService->record($session, LiveChatAuthorType::AGENT, $bubble);
                } catch (Throwable $exception) {
                    $this->logger->warning('Live chat answer: message not added to the chat', [
                        'live_chat_question_id' => $question->getId(),
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
            $this->notifyVisitor($question, $session, $organizer, $event, implode("\n", $relayed->messages));
        }

        if ($question->getEventId() !== null) {
            $this->knowledgeService->addFact(
                eventId: $question->getEventId(),
                organizerId: $question->getOrganizerId(),
                answer: $relayed->fact ?? (string) $question->getAnswer(),
                source: EventAssistantFactSource::ORGANIZER,
                subject: $question->getQuestion(),
                questionId: $question->getId(),
            );
        }

        $this->questionRepository->updateFromArray($question->getId(), [
            LiveChatQuestionDomainObjectAbstract::STATUS => LiveChatQuestionStatus::RELAYED->name,
            LiveChatQuestionDomainObjectAbstract::RELAYED_AT => Carbon::now('UTC')->toDateTimeString(),
        ]);

        return true;
    }

    private function notifyVisitor(
        LiveChatQuestionDomainObject $question,
        LiveChatSessionDomainObject $session,
        OrganizerDomainObject $organizer,
        ?EventDomainObject $event,
        string $text,
    ): void {
        $locale = $this->localeResolver->forOrganizerId($question->getOrganizerId());
        $intro = __('Answer to your question about :subject:', ['subject' => $event?->getTitle() ?? $organizer->getName()], $locale);

        $phone = $session->getVisitorPhone();
        if ($phone !== null && $phone !== '') {
            try {
                $this->sendService->send(new SendSmsDTO(
                    organizerId: $question->getOrganizerId(),
                    phone: $phone,
                    content: $intro."\n".$text,
                    authorType: SmsMessageAuthorType::AUTOMATIC,
                    keepDraft: true,
                ));

                return;
            } catch (SmsRecipientOptedOutException|SmsGatewayNotConfiguredException|InvalidSmsPhoneNumberException|SmsGatewayException $exception) {
                $this->logger->info('Live chat answer: SMS to the visitor not sent', [
                    'live_chat_question_id' => $question->getId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $email = $session->getVisitorEmail();
        if ($email === null || $email === '') {
            return;
        }

        try {
            $this->mailer
                ->to($email)
                ->locale($locale)
                ->send(new LiveChatAnswerMail($event, $organizer, $intro."\n".$text));
        } catch (Throwable $exception) {
            $this->logger->error('Live chat answer: e-mail to the visitor not sent', [
                'live_chat_question_id' => $question->getId(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function session(LiveChatQuestionDomainObject $question): ?LiveChatSessionDomainObject
    {
        return $question->getLiveChatSessionId() === null
            ? null
            : $this->sessionRepository->findFirstWhere([LiveChatSessionDomainObjectAbstract::ID => $question->getLiveChatSessionId()]);
    }

    private function event(LiveChatQuestionDomainObject $question): ?EventDomainObject
    {
        return $question->getEventId() === null
            ? null
            : $this->eventRepository->findFirstWhere([EventDomainObjectAbstract::ID => $question->getEventId()]);
    }
}
