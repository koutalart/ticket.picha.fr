<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Loyalty;

use HiEvents\DomainObjects\LoyaltyCardDomainObject;
use HiEvents\Exceptions\LoyaltyCardException;
use HiEvents\Exceptions\MissingPhoneCallingCodeException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Helper\PhoneNormalizer;
use HiEvents\Repository\Interfaces\LoyaltyCardRepositoryInterface;
use HiEvents\Repository\Interfaces\LoyaltyProgramRepositoryInterface;
use HiEvents\Services\Application\Handlers\Loyalty\DTO\EnrollLoyaltyCardDTO;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Support\Str;

class EnrollLoyaltyCardHandler
{
    private const DEFAULT_CALLING_CODE = '262';

    public function __construct(
        private readonly LoyaltyProgramRepositoryInterface $programRepository,
        private readonly LoyaltyCardRepositoryInterface $cardRepository,
        private readonly HtmlPurifierService $purifier,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws LoyaltyCardException
     */
    public function handle(EnrollLoyaltyCardDTO $dto): LoyaltyCardDomainObject
    {
        $program = $this->programRepository->findFirstWhere([
            'organizer_id' => $dto->organizer_id,
            'is_active' => true,
        ]);

        if ($program === null) {
            throw new ResourceNotFoundException(__('This loyalty program is not available.'));
        }

        $firstName = $this->cleanName($dto->first_name);
        if ($firstName === '') {
            throw new LoyaltyCardException(__('The first name is required.'));
        }

        $email = $dto->email === null || trim($dto->email) === '' ? null : mb_strtolower(trim($dto->email));
        $phone = $this->normalizePhone($dto->phone);

        if ($email === null && $phone === null) {
            throw new LoyaltyCardException(__('Enter your email address or your phone number.'));
        }

        $existing = $this->findExisting($program->getId(), $email, $phone);

        if ($existing !== null) {
            $missing = array_filter([
                'email' => $existing->getEmail() === null ? $email : null,
                'phone' => $existing->getPhone() === null ? $phone : null,
            ]);

            return $missing === [] || $this->conflicts($program->getId(), $existing->getId(), $missing)
                ? $existing
                : $this->cardRepository->updateFromArray($existing->getId(), $missing);
        }

        return $this->cardRepository->create([
            'loyalty_program_id' => $program->getId(),
            'organizer_id' => $dto->organizer_id,
            'public_id' => Str::random(32),
            'first_name' => $firstName,
            'email' => $email,
            'phone' => $phone,
        ]);
    }

    private function findExisting(int $programId, ?string $email, ?string $phone): ?LoyaltyCardDomainObject
    {
        if ($email !== null) {
            $card = $this->cardRepository->findFirstWhere(['loyalty_program_id' => $programId, 'email' => $email]);
            if ($card !== null) {
                return $card;
            }
        }

        if ($phone !== null) {
            return $this->cardRepository->findFirstWhere(['loyalty_program_id' => $programId, 'phone' => $phone]);
        }

        return null;
    }

    /**
     * @param  array<string, string>  $missing
     */
    private function conflicts(int $programId, int $cardId, array $missing): bool
    {
        foreach ($missing as $field => $value) {
            $other = $this->cardRepository->findFirstWhere(['loyalty_program_id' => $programId, $field => $value]);
            if ($other !== null && $other->getId() !== $cardId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws LoyaltyCardException
     */
    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        try {
            $normalized = PhoneNormalizer::normalize($phone, self::DEFAULT_CALLING_CODE);
        } catch (MissingPhoneCallingCodeException) {
            $normalized = null;
        }

        $digits = preg_replace('/\D+/', '', (string) $normalized) ?? '';
        if ($normalized === null || strlen($digits) < 9 || strlen($digits) > 15) {
            throw new LoyaltyCardException(__('The phone number is not valid.'));
        }

        return $normalized;
    }

    private function cleanName(string $value): string
    {
        $text = trim(html_entity_decode(strip_tags((string) $this->purifier->purify($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 40);
    }
}
