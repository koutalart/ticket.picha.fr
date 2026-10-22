<?php

namespace HiEvents\Services\Application\Handlers\TicketLookup;

use Carbon\Carbon;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Mail\TicketLookup\TicketLookupEmail;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\TicketLookupTokenRepositoryInterface;
use HiEvents\Services\Application\Handlers\TicketLookup\DTO\SendTicketLookupEmailDTO;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;
use HiEvents\Services\Infrastructure\TokenGenerator\TokenGeneratorService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

class SendTicketLookupEmailHandler
{
    private const TOKEN_EXPIRY_HOURS = 24;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly TicketLookupTokenRepositoryInterface $ticketLookupTokenRepository,
        private readonly TokenGeneratorService $tokenGeneratorService,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
        private readonly DatabaseManager $databaseManager,
        private readonly PublicBaseUrlResolver $publicBaseUrlResolver,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function handle(SendTicketLookupEmailDTO $dto): void
    {
        $email = strtolower($dto->email);

        $orders = $this->findOrdersByEmail($email);

        if ($orders->isEmpty()) {
            $this->logger->info('Ticket lookup requested for email with no orders', [
                'email' => $email,
            ]);
            return;
        }

        $orderCount = $orders->count();
        $publicBaseUrl = $this->publicBaseUrl($orders);

        $this->databaseManager->transaction(function () use ($email, $orderCount, $publicBaseUrl) {
            $token = $this->generateAndSaveToken($email);
            $this->sendTicketLookupEmail($email, $token, $orderCount, $publicBaseUrl);
        });
    }

    private function findOrdersByEmail(string $email): Collection
    {
        return $this->orderRepository->findWhere(
            [
                [OrderDomainObjectAbstract::EMAIL, '=', $email],
                [OrderDomainObjectAbstract::STATUS, '=', OrderStatus::COMPLETED->name],
            ],
        );
    }

    /**
     * The organizer's own domain when every order belongs to an organizer that has one, null for the platform.
     */
    private function publicBaseUrl(Collection $orders): ?string
    {
        $baseUrls = $orders
            ->map(fn (OrderDomainObject $order) => $order->getEventId())
            ->unique()
            ->map(fn (int $eventId) => $this->publicBaseUrlResolver->forEventId($eventId))
            ->unique();

        $baseUrl = $baseUrls->count() === 1 ? $baseUrls->first() : null;

        return $baseUrl !== $this->publicBaseUrlResolver->platformUrl() ? $baseUrl : null;
    }

    private function generateAndSaveToken(string $email): string
    {
        $token = $this->tokenGeneratorService->generateToken(prefix: 'tl');

        $this->ticketLookupTokenRepository->deleteWhere(['email' => $email]);
        $this->ticketLookupTokenRepository->create([
            'email' => $email,
            'token' => $token,
            'expires_at' => Carbon::now()->addHours(self::TOKEN_EXPIRY_HOURS)->toDateTimeString(),
        ]);

        return $token;
    }

    private function sendTicketLookupEmail(string $email, string $token, int $orderCount, ?string $publicBaseUrl): void
    {
        $this->logger->info('Sending ticket lookup email', [
            'email' => $email,
            'order_count' => $orderCount,
        ]);

        $this->mailer
            ->to($email)
            ->locale(CustomerLocale::get())
            ->queue(new TicketLookupEmail(
                email: $email,
                token: $token,
                orderCount: $orderCount,
                publicBaseUrl: $publicBaseUrl,
            ));
    }
}
