<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\Generated\ContactDomainObjectAbstract;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Domain\QrLink\QrLinkUrlService;

/**
 * One tracked link per contact (?c=<public code>), reused on every channel so visits and orders land on the same contact.
 */
class ContactPersonalLinkService
{
    public const QUERY_PARAM = 'c';

    private const CODE_PATTERN = '/^[a-z0-9]{6,16}$/';

    private const MAX_MERGE_HOPS = 5;

    public function __construct(
        private readonly ContactRepositoryInterface $contactRepository,
    ) {}

    public static function normalizeCode(?string $code): ?string
    {
        $code = strtolower(trim((string) $code));

        return preg_match(self::CODE_PATTERN, $code) ? $code : null;
    }

    public function findByCode(?string $code, ?int $organizerId = null): ?ContactDomainObject
    {
        $code = self::normalizeCode($code);
        if ($code === null) {
            return null;
        }

        $where = [ContactDomainObjectAbstract::PUBLIC_CODE => $code];
        if ($organizerId !== null) {
            $where[ContactDomainObjectAbstract::ORGANIZER_ID] = $organizerId;
        }

        /** @var ContactDomainObject|null $contact */
        $contact = $this->contactRepository->findFirstWhere($where);

        return $contact === null ? null : $this->resolveMerged($contact);
    }

    public function findById(int $contactId): ?ContactDomainObject
    {
        /** @var ContactDomainObject|null $contact */
        $contact = $this->contactRepository->findFirst($contactId);

        return $contact === null ? null : $this->resolveMerged($contact);
    }

    public function resolveMerged(ContactDomainObject $contact): ContactDomainObject
    {
        for ($hop = 0; $hop < self::MAX_MERGE_HOPS && $contact->getMergedIntoContactId() !== null; $hop++) {
            /** @var ContactDomainObject|null $target */
            $target = $this->contactRepository->findFirst($contact->getMergedIntoContactId());
            if ($target === null) {
                break;
            }
            $contact = $target;
        }

        return $contact;
    }

    public function getUrl(string $baseUrl, ContactDomainObject $contact): string
    {
        $fragment = '';
        if (($hashPosition = strpos($baseUrl, '#')) !== false) {
            $fragment = substr($baseUrl, $hashPosition);
            $baseUrl = substr($baseUrl, 0, $hashPosition);
        }

        [$path, $queryString] = array_pad(explode('?', $baseUrl, 2), 2, '');
        parse_str($queryString, $query);
        unset($query[QrLinkUrlService::QR_QUERY_PARAM], $query[QrLinkUrlService::QR_COUNTED_PARAM]);
        $query[self::QUERY_PARAM] = $contact->getPublicCode();

        return $path.'?'.http_build_query($query).$fragment;
    }
}
