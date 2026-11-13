<?php

namespace HiEvents\Services\Domain\EventFeedback;

use HiEvents\DomainObjects\EventFeedbackDomainObject;
use HiEvents\Repository\Interfaces\EventFeedbackRepositoryInterface;

class EventFeedbackCsvService
{
    public function __construct(
        private readonly EventFeedbackRepositoryInterface $feedbackRepository,
    ) {}

    public function build(int $eventId): string
    {
        $rows = $this->feedbackRepository->findWhere(['event_id' => $eventId])
            ->sortBy(fn (EventFeedbackDomainObject $feedback) => $feedback->getId());

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['date', 'c', 'note', 'titre', 'prenom', 'artistes', 'accueil', 'revenir', 'mot', 'publier'], ';');

        foreach ($rows as $feedback) {
            fputcsv($handle, [
                $feedback->getUpdatedAt() ?? $feedback->getCreatedAt(),
                $feedback->getContactCode(),
                $feedback->getRating(),
                $feedback->getTitle(),
                $feedback->getFirstName(),
                $feedback->getArtists(),
                $feedback->getWelcome(),
                $feedback->getComeBack(),
                $feedback->getMessage(),
                $feedback->getPublishConsent() ? 'oui' : 'non',
            ], ';');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
