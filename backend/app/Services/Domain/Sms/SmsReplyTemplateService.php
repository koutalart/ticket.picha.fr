<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\SmsReplyTemplateDomainObjectAbstract;
use HiEvents\DomainObjects\SmsReplyTemplateDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsReplyTemplateValidationException;
use HiEvents\Helper\SmsTextSanitizer;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\SmsReplyTemplateRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsReplyTemplateDTO;
use Illuminate\Support\Collection;

class SmsReplyTemplateService
{
    public const MAX_TEMPLATES_PER_ORGANIZER = 100;

    public function __construct(
        private readonly SmsReplyTemplateRepositoryInterface $templateRepository,
    ) {}

    /**
     * @return Collection<SmsReplyTemplateDomainObject>
     */
    public function list(int $organizerId): Collection
    {
        return $this->templateRepository->findWhere(
            where: [SmsReplyTemplateDomainObjectAbstract::ORGANIZER_ID => $organizerId],
            orderAndDirections: [new OrderAndDirection(SmsReplyTemplateDomainObjectAbstract::NAME)],
        );
    }

    /**
     * @throws SmsReplyTemplateValidationException
     */
    public function create(SmsReplyTemplateDTO $dto): SmsReplyTemplateDomainObject
    {
        $count = $this->templateRepository->countWhere([
            SmsReplyTemplateDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
        ]);

        if ($count >= self::MAX_TEMPLATES_PER_ORGANIZER) {
            throw new SmsReplyTemplateValidationException(
                __('You can save up to :max reply templates', ['max' => self::MAX_TEMPLATES_PER_ORGANIZER])
            );
        }

        return $this->templateRepository->create([
            SmsReplyTemplateDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
            ...$this->sanitizedAttributes($dto),
        ]);
    }

    /**
     * @throws ResourceNotFoundException
     * @throws SmsReplyTemplateValidationException
     */
    public function update(int $templateId, SmsReplyTemplateDTO $dto): SmsReplyTemplateDomainObject
    {
        $where = $this->ownedBy($templateId, $dto->organizerId);

        return $this->templateRepository->updateByIdWhere(
            id: $templateId,
            attributes: $this->sanitizedAttributes($dto),
            where: $where,
        );
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function delete(int $organizerId, int $templateId): void
    {
        $this->templateRepository->deleteWhere($this->ownedBy($templateId, $organizerId));
    }

    /**
     * @throws ResourceNotFoundException
     */
    private function ownedBy(int $templateId, int $organizerId): array
    {
        $where = [
            SmsReplyTemplateDomainObjectAbstract::ID => $templateId,
            SmsReplyTemplateDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ];

        if ($this->templateRepository->findFirstWhere($where) === null) {
            throw new ResourceNotFoundException(__('Reply template not found'));
        }

        return $where;
    }

    /**
     * @throws SmsReplyTemplateValidationException
     */
    private function sanitizedAttributes(SmsReplyTemplateDTO $dto): array
    {
        $name = preg_replace('/\s+/u', ' ', SmsTextSanitizer::clean($dto->name)) ?? '';
        $content = SmsTextSanitizer::clean($dto->content);

        if ($name === '' || $content === '') {
            throw new SmsReplyTemplateValidationException(__('The template name and text cannot be empty'));
        }

        return [
            SmsReplyTemplateDomainObjectAbstract::NAME => $name,
            SmsReplyTemplateDomainObjectAbstract::CONTENT => $content,
        ];
    }
}
