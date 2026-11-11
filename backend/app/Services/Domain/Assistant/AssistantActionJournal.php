<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Assistant;

use Carbon\Carbon;
use HiEvents\DomainObjects\AssistantActionDomainObject;
use HiEvents\DomainObjects\Generated\AssistantActionDomainObjectAbstract as Action;
use HiEvents\DomainObjects\Status\AssistantActionStatus;
use HiEvents\Exceptions\AssistantConfirmationException;
use HiEvents\Repository\Interfaces\AssistantActionRepositoryInterface;
use HiEvents\Services\Domain\Assistant\DTO\AssistantActionContextDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantConfirmationRequestDTO;
use Psr\Log\LoggerInterface;

/**
 * Trace of every action the assistant (Anicka) runs from the command line: who asked, what, when, and the result.
 * A level 2 action is first recorded as awaiting confirmation with a single-use token, valid TOKEN_TTL_HOURS hours,
 * bound to the same command, organizer and parameters.
 */
class AssistantActionJournal
{
    public const TOKEN_TTL_HOURS = 4;

    private const TOKEN_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const TOKEN_LENGTH = 8;

    public function __construct(
        private readonly AssistantActionRepositoryInterface $actionRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function start(AssistantActionContextDTO $context, string $summary): AssistantActionDomainObject
    {
        return $this->create($context, AssistantActionStatus::CONFIRMED, [Action::SUMMARY => $summary]);
    }

    public function refuse(AssistantActionContextDTO $context, string $reason): AssistantActionDomainObject
    {
        return $this->create($context, AssistantActionStatus::REFUSED, [Action::ERROR => $reason]);
    }

    public function requestConfirmation(AssistantActionContextDTO $context, string $summary): AssistantConfirmationRequestDTO
    {
        $token = $this->generateToken();
        $expiresAt = Carbon::now('UTC')->addHours(self::TOKEN_TTL_HOURS);

        $action = $this->create($context, AssistantActionStatus::AWAITING_CONFIRMATION, [
            Action::SUMMARY => $summary,
            Action::CONFIRMATION_TOKEN_HASH => $this->hashToken($token),
            Action::CONFIRMATION_EXPIRES_AT => $expiresAt->toDateTimeString(),
        ]);

        return new AssistantConfirmationRequestDTO(
            actionId: $action->getId(),
            token: $token,
            expiresAt: $expiresAt->toIso8601String(),
        );
    }

    /**
     * Uses the token once: the action becomes CONFIRMED and can run.
     *
     * @throws AssistantConfirmationException
     */
    public function consumeConfirmation(AssistantActionContextDTO $context, string $token): AssistantActionDomainObject
    {
        /** @var AssistantActionDomainObject|null $action */
        $action = $this->actionRepository->findFirstWhere([
            Action::CONFIRMATION_TOKEN_HASH => $this->hashToken($token),
        ]);

        if ($action === null) {
            throw new AssistantConfirmationException(__('Unknown confirmation token.'));
        }

        if ($action->getStatus() !== AssistantActionStatus::AWAITING_CONFIRMATION->value) {
            throw new AssistantConfirmationException(__('This confirmation token was already used.'));
        }

        if (Carbon::parse($action->getConfirmationExpiresAt(), 'UTC')->isPast()) {
            throw new AssistantConfirmationException(__('This confirmation token has expired. Ask again for a new one.'));
        }

        if ($action->getCommand() !== $context->command
            || $action->getOrganizerId() !== $context->organizerId
            || $action->getParamsHash() !== $this->hashParams($context->params)) {
            throw new AssistantConfirmationException(
                __('This confirmation token was given for another action or other values. Nothing was done.')
            );
        }

        $updated = $this->actionRepository->updateWhere(
            attributes: [
                Action::STATUS => AssistantActionStatus::CONFIRMED->value,
                Action::CONFIRMED_AT => Carbon::now('UTC')->toDateTimeString(),
                Action::REQUESTED_BY => $this->requestedBy($action->getRequestedBy(), $context->requestedBy),
            ],
            where: [
                Action::ID => $action->getId(),
                Action::STATUS => AssistantActionStatus::AWAITING_CONFIRMATION->value,
            ],
        );

        if ($updated !== 1) {
            throw new AssistantConfirmationException(__('This confirmation token was already used.'));
        }

        $this->logger->info('Assistant action confirmed', ['assistant_action_id' => $action->getId()]);

        return $this->actionRepository->findById($action->getId());
    }

    public function markExecuted(int $actionId, array $result): void
    {
        $this->actionRepository->updateFromArray($actionId, [
            Action::STATUS => AssistantActionStatus::EXECUTED->value,
            Action::RESULT => $result,
            Action::EXECUTED_AT => Carbon::now('UTC')->toDateTimeString(),
        ]);

        $this->logger->info('Assistant action executed', ['assistant_action_id' => $actionId]);
    }

    public function markFailed(int $actionId, string $error): void
    {
        $this->actionRepository->updateFromArray($actionId, [
            Action::STATUS => AssistantActionStatus::FAILED->value,
            Action::ERROR => mb_substr($error, 0, 2000),
        ]);

        $this->logger->warning('Assistant action failed', ['assistant_action_id' => $actionId, 'error' => $error]);
    }

    public function hashParams(array $params): string
    {
        return hash('sha256', json_encode($this->normalize($params), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function create(AssistantActionContextDTO $context, AssistantActionStatus $status, array $extra = []): AssistantActionDomainObject
    {
        /** @var AssistantActionDomainObject $action */
        $action = $this->actionRepository->create([
            Action::COMMAND => $context->command,
            Action::LEVEL => $context->level,
            Action::STATUS => $status->value,
            Action::ACCOUNT_ID => $context->accountId,
            Action::ORGANIZER_ID => $context->organizerId,
            Action::EVENT_ID => $context->eventId,
            Action::REQUESTED_BY => mb_substr($context->requestedBy, 0, 150),
            Action::PARAMS => $context->params,
            Action::PARAMS_HASH => $this->hashParams($context->params),
            ...$extra,
        ]);

        $this->logger->info('Assistant action recorded', [
            'assistant_action_id' => $action->getId(),
            'command' => $context->command,
            'status' => $status->value,
            'organizer_id' => $context->organizerId,
            'event_id' => $context->eventId,
            'requested_by' => $context->requestedBy,
        ]);

        return $action;
    }

    private function normalize(array $params): array
    {
        ksort($params);

        return array_map(fn ($value) => is_array($value) ? $this->normalize($value) : $value, $params);
    }

    private function requestedBy(string $initial, string $confirmedBy): string
    {
        if ($confirmedBy === '' || $confirmedBy === $initial) {
            return $initial;
        }

        return mb_substr($initial.' | '.__('confirmed by').' '.$confirmedBy, 0, 150);
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', strtoupper(trim($token)));
    }

    private function generateToken(): string
    {
        $token = '';
        $max = strlen(self::TOKEN_ALPHABET) - 1;

        for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
            $token .= self::TOKEN_ALPHABET[random_int(0, $max)];
        }

        return $token;
    }
}
