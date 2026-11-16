<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use Carbon\Carbon;
use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Exceptions\AssistantConfirmationException;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Assistant\AssistantActionJournal;
use HiEvents\Services\Domain\Assistant\AssistantScopeResolver;
use HiEvents\Services\Domain\Assistant\DTO\AssistantActionContextDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Actions the assistant (Anicka) runs on the production server. Every call prints one JSON line and is written
 * to the assistant_actions journal.
 *
 * Level 1 runs at once. Level 2 first answers with a summary and a single-use token (statut
 * "confirmation_requise"); the same command with --confirme=<token> and the same values runs it.
 * Level 3 (payouts, Stripe settings, platform fees) has no command, on purpose.
 */
abstract class AbstractAssistantCommand extends Command
{
    private const COMMON_OPTIONS = '
        {--organisateur= : Id of the organizer the action is done for (required)}
        {--par=Anicka : Who asked for the action, e.g. "Jo, SMS du 11/11"}';

    private const CONFIRM_OPTION = '
        {--confirme= : Single-use confirmation token given by the first call}';

    private const EXCLUDED_FROM_PARAMS = ['organisateur', 'evenement', 'par', 'confirme', 'help', 'quiet', 'verbose',
        'version', 'ansi', 'no-ansi', 'no-interaction', 'env', 'silent'];

    private const BY_OPTION = '
        {--par=Anicka : Who asked for the action, e.g. "Jo, SMS du 11/11"}';

    public function __construct()
    {
        $this->signature .= ($this->requiresOrganizer() ? self::COMMON_OPTIONS : self::BY_OPTION)
            .($this->level() === 2 ? self::CONFIRM_OPTION : '');

        parent::__construct();
    }

    abstract protected function level(): int;

    /**
     * Checks the current state and returns one sentence saying what will be done.
     *
     * @throws AssistantActionRefusedException
     */
    abstract protected function describe(AssistantScopeDTO $scope): string;

    /**
     * @return array the result printed under "resultat"
     */
    abstract protected function perform(AssistantScopeDTO $scope): array;

    protected function requiresEvent(): bool
    {
        return true;
    }

    /**
     * False only for an action that creates the organizer itself: the scope then has no organizer.
     */
    protected function requiresOrganizer(): bool
    {
        return true;
    }

    /**
     * @throws AssistantActionRefusedException
     */
    protected function validateInput(): void {}

    final public function handle(AssistantActionJournal $journal, AssistantScopeResolver $resolver): int
    {
        App::setLocale('fr');

        $params = $this->actionParams();
        $scope = null;

        try {
            if ($this->requiresOrganizer()) {
                $organizerId = $this->requiredId('organisateur');
                $eventId = $this->requiresEvent() ? $this->requiredId('evenement') : $this->optionalId('evenement');
                $this->validateInput();

                $scope = $resolver->resolve($organizerId, $eventId);
            } else {
                $this->validateInput();
                $scope = new AssistantScopeDTO;
            }

            $token = $this->level() === 2 ? $this->optionalString('confirme') : null;

            if ($token === null) {
                $summary = $this->describe($scope);

                if ($this->level() === 2) {
                    $confirmation = $journal->requestConfirmation($this->actionContext($scope, $params), $summary);

                    return $this->respond([
                        'ok' => true,
                        'statut' => 'confirmation_requise',
                        'action' => $this->getName(),
                        'niveau' => 2,
                        'journal_id' => $confirmation->actionId,
                        'resume' => $summary,
                        'jeton' => $confirmation->token,
                        'expire_le' => $confirmation->expiresAt,
                    ]);
                }

                $action = $journal->start($this->actionContext($scope, $params), $summary);
            } else {
                $action = $journal->consumeConfirmation($this->actionContext($scope, $params), $token);
                $summary = $action->getSummary() ?? '';
            }
        } catch (AssistantActionRefusedException|AssistantConfirmationException $exception) {
            $refusal = $journal->refuse($this->actionContext($scope, $params), $exception->getMessage());

            return $this->respondError($exception->getMessage(), $refusal->getId(), 'refuse');
        }

        try {
            if ($token !== null) {
                $this->describe($scope);
            }

            $result = $this->perform($scope);
        } catch (Throwable $exception) {
            $message = $this->errorMessage($exception);
            $journal->markFailed($action->getId(), $message);

            if (! $exception instanceof AssistantActionRefusedException) {
                report($exception);
            }

            return $this->respondError($message, $action->getId(), 'echec');
        }

        $journal->markExecuted($action->getId(), $result);

        return $this->respond([
            'ok' => true,
            'statut' => 'execute',
            'action' => $this->getName(),
            'niveau' => $this->level(),
            'journal_id' => $action->getId(),
            'resume' => $summary,
            'resultat' => $result,
        ]);
    }

    /**
     * @throws AssistantActionRefusedException
     */
    protected function requiredId(string $option): int
    {
        $id = $this->optionalId($option);

        if ($id === null) {
            throw new AssistantActionRefusedException(__('The option --:option is required.', ['option' => $option]));
        }

        return $id;
    }

    /**
     * @throws AssistantActionRefusedException
     */
    protected function optionalId(string $option): ?int
    {
        if (! $this->hasOption($option)) {
            return null;
        }

        $value = trim((string) $this->option($option));

        if ($value === '') {
            return null;
        }

        if (! ctype_digit($value) || (int) $value <= 0) {
            throw new AssistantActionRefusedException(__('The option --:option must be a positive whole number.', ['option' => $option]));
        }

        return (int) $value;
    }

    /**
     * @return int[]
     *
     * @throws AssistantActionRefusedException
     */
    protected function idList(string $option): array
    {
        $value = trim((string) $this->option($option));

        if ($value === '') {
            return [];
        }

        $ids = array_map('trim', explode(',', $value));

        foreach ($ids as $id) {
            if (! ctype_digit($id) || (int) $id <= 0) {
                throw new AssistantActionRefusedException(__('The option --:option must be a list of ids separated by commas.', ['option' => $option]));
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @throws AssistantActionRefusedException
     */
    protected function requiredString(string $option): string
    {
        $value = $this->optionalString($option);

        if ($value === null) {
            throw new AssistantActionRefusedException(__('The option --:option is required.', ['option' => $option]));
        }

        return $value;
    }

    protected function optionalString(string $option): ?string
    {
        $value = trim((string) $this->option($option));

        return $value === '' ? null : $value;
    }

    /**
     * @throws AssistantActionRefusedException
     */
    protected function amount(string $option, bool $required = true): ?float
    {
        $value = str_replace([' ', ','], ['', '.'], (string) $this->option($option));

        if ($value === '') {
            if ($required) {
                throw new AssistantActionRefusedException(__('The option --:option is required.', ['option' => $option]));
            }

            return null;
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw new AssistantActionRefusedException(__('The option --:option must be an amount, e.g. 12.50.', ['option' => $option]));
        }

        return round((float) $value, 2);
    }

    /**
     * @throws AssistantActionRefusedException
     */
    protected function choiceOption(string $option, array $allowed, ?string $default = null): string
    {
        $value = mb_strtolower($this->optionalString($option) ?? (string) $default);

        if (! in_array($value, $allowed, true)) {
            throw new AssistantActionRefusedException(__('The option --:option must be one of: :allowed.', [
                'option' => $option,
                'allowed' => implode(', ', $allowed),
            ]));
        }

        return $value;
    }

    /**
     * @return string|null "Y-m-d H:i" in the event time zone
     *
     * @throws AssistantActionRefusedException
     */
    protected function localDateTime(string $option): ?string
    {
        $value = $this->optionalString($option);

        if ($value === null) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d H:i', $value);
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof Carbon || $date->format('Y-m-d H:i') !== $value) {
            throw new AssistantActionRefusedException(__('The option --:option must be written "Y-m-d H:i", e.g. 2026-12-24 18:30.', ['option' => $option]));
        }

        return $value;
    }

    /**
     * @throws AssistantActionRefusedException
     */
    protected function order(AssistantScopeDTO $scope, string $option = 'commande'): OrderDomainObject
    {
        /** @var OrderDomainObject|null $order */
        $order = $this->laravel->make(OrderRepositoryInterface::class)->findFirstWhere([
            'id' => $this->requiredId($option),
            'event_id' => $scope->eventId(),
        ]);

        if ($order === null) {
            throw new AssistantActionRefusedException(__('Order :id not found for this event.', ['id' => $this->requiredId($option)]));
        }

        return $order;
    }

    /**
     * @throws AssistantActionRefusedException
     */
    protected function accountOwnerUserId(AssistantScopeDTO $scope): int
    {
        /** @var AccountUserDomainObject|null $owner */
        $owner = $this->laravel->make(AccountUserRepositoryInterface::class)->findFirstWhere([
            'account_id' => $scope->accountId(),
            'is_account_owner' => true,
        ]);

        if ($owner === null) {
            throw new AssistantActionRefusedException(__('This organizer has no account owner.'));
        }

        return $owner->getUserId();
    }

    /**
     * A yes/no option: oui, non, true, false, 1 or 0.
     *
     * @throws AssistantActionRefusedException
     */
    protected function yesNo(string $option, bool $default): bool
    {
        $value = $this->optionalString($option);

        if ($value === null) {
            return $default;
        }

        return match (mb_strtolower($value)) {
            'oui', 'true', '1', 'yes' => true,
            'non', 'false', '0', 'no' => false,
            default => throw new AssistantActionRefusedException(__('The option --:option must be oui or non.', ['option' => $option])),
        };
    }

    protected function money(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', ' ').' '.$currency;
    }

    private function actionParams(): array
    {
        $params = [];

        foreach ($this->options() as $name => $value) {
            if (in_array($name, self::EXCLUDED_FROM_PARAMS, true)) {
                continue;
            }

            if ($value === null || $value === false || $value === '') {
                continue;
            }

            $params[$name] = is_bool($value) ? $value : trim((string) $value);
        }

        return $params;
    }

    private function actionContext(?AssistantScopeDTO $scope, array $params): AssistantActionContextDTO
    {
        return new AssistantActionContextDTO(
            command: (string) $this->getName(),
            level: $this->level(),
            requestedBy: $this->optionalString('par') ?? 'Anicka',
            params: [
                ...$params,
                'organisateur' => $scope?->organizer?->getId() ?? ($this->hasOption('organisateur') ? $this->option('organisateur') : null),
                'evenement' => $scope?->event?->getId() ?? ($this->hasOption('evenement') ? $this->option('evenement') : null),
            ],
            accountId: $scope?->organizer?->getAccountId(),
            organizerId: $scope?->organizer?->getId(),
            eventId: $scope?->event?->getId(),
        );
    }

    private function errorMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return implode(' ', array_merge(...array_values($exception->errors())));
        }

        $message = trim($exception->getMessage());

        return $message !== '' ? $message : class_basename($exception);
    }

    private function respondError(string $message, ?int $actionId, string $status): int
    {
        $this->respond([
            'ok' => false,
            'statut' => $status,
            'action' => $this->getName(),
            'niveau' => $this->level(),
            'journal_id' => $actionId,
            'erreur' => $message,
        ]);

        return self::FAILURE;
    }

    private function respond(array $payload): int
    {
        $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
