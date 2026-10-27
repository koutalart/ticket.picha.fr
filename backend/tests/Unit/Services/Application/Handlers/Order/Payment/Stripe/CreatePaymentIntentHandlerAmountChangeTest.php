<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Order\Payment\Stripe;

use Carbon\Carbon;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripePaymentsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\CreatePaymentIntentHandler;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\CreatePaymentIntentResponseDTO;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentCreationService;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentInvalidationService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use HiEvents\Services\Infrastructure\Stripe\StripeConfigurationService;
use Mockery;
use Mockery\MockInterface;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Tests\TestCase;

class CreatePaymentIntentHandlerAmountChangeTest extends TestCase
{
    private StripePaymentIntentCreationService|MockInterface $stripePaymentService;

    private StripePaymentIntentInvalidationService|MockInterface $invalidationService;

    private StripePaymentsRepositoryInterface|MockInterface $stripePaymentsRepository;

    private StripePaymentDomainObject $stripePayment;

    private CreatePaymentIntentHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripePayment = (new StripePaymentDomainObject)->setId(5)->setPaymentIntentId('pi_old');

        $order = (new OrderDomainObject)
            ->setId(10)
            ->setEventId(7)
            ->setShortId('o_test')
            ->setSessionId('session')
            ->setStatus(OrderStatus::RESERVED->name)
            ->setReservedUntil(Carbon::now()->addMinutes(10)->toDateTimeString())
            ->setTotalGross(15.49)
            ->setCurrency('EUR')
            ->setOrderItems(collect())
            ->setEvent((new EventDomainObject)->setTitle('Halloween'))
            ->setStripePayment($this->stripePayment);

        $orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $orderRepository->shouldReceive('findByShortId')->andReturn($order);

        $account = Mockery::mock(AccountDomainObject::class);
        $account->shouldReceive('getActiveStripePlatform')->andReturnNull();
        $account->shouldReceive('getActiveStripeAccountId')->andReturnNull();
        $account->shouldReceive('getAccountVatSetting')->andReturnNull();
        $accountRepository = Mockery::mock(AccountRepositoryInterface::class);
        $accountRepository->shouldReceive('loadRelation')->andReturnSelf();
        $accountRepository->shouldReceive('findByEventId')->andReturn($account);

        $session = Mockery::mock(CheckoutSessionManagementService::class);
        $session->shouldReceive('verifySession')->andReturnTrue();

        $clientFactory = Mockery::mock(StripeClientFactory::class);
        $clientFactory->shouldReceive('createForPlatform')->andReturn(new StripeClient('sk_test_dummy'));

        $configuration = Mockery::mock(StripeConfigurationService::class);
        $configuration->shouldReceive('getPrimaryPlatform')->andReturnNull();
        $configuration->shouldReceive('getPublicKey')->andReturn('pk_test');

        $this->stripePaymentService = Mockery::mock(StripePaymentIntentCreationService::class);
        $this->invalidationService = Mockery::mock(StripePaymentIntentInvalidationService::class);
        $this->stripePaymentsRepository = Mockery::mock(StripePaymentsRepositoryInterface::class);

        $this->handler = new CreatePaymentIntentHandler(
            $orderRepository,
            $this->stripePaymentService,
            $session,
            $this->stripePaymentsRepository,
            $accountRepository,
            $clientFactory,
            $configuration,
            $this->invalidationService,
        );
    }

    public function test_the_existing_payment_intent_is_reused_when_the_amount_is_unchanged(): void
    {
        $this->givenExistingPaymentIntent(amount: 1549);
        $this->invalidationService->shouldNotReceive('invalidate');
        $this->stripePaymentService->shouldNotReceive('createPaymentIntentWithClient');

        $response = $this->handler->handle('o_test');

        $this->assertSame('pi_old', $response->paymentIntentId);
        $this->assertSame('secret_old', $response->clientSecret);
    }

    public function test_a_new_payment_intent_is_created_when_the_order_amount_changed(): void
    {
        $this->givenExistingPaymentIntent(amount: 1500);
        $this->invalidationService->shouldReceive('invalidate')->once()->with($this->stripePayment);
        $this->stripePaymentService->shouldReceive('createPaymentIntentWithClient')
            ->once()
            ->withArgs(fn ($client, $dto) => $dto->amount->toMinorUnit() === 1549)
            ->andReturn(new CreatePaymentIntentResponseDTO(paymentIntentId: 'pi_new', clientSecret: 'secret_new'));
        $this->stripePaymentsRepository->shouldReceive('create')->once();

        $response = $this->handler->handle('o_test');

        $this->assertSame('pi_new', $response->paymentIntentId);
        $this->assertSame('secret_new', $response->clientSecret);
    }

    public function test_a_payment_intent_already_processing_is_never_replaced(): void
    {
        $this->givenExistingPaymentIntent(amount: 1500, status: PaymentIntent::STATUS_PROCESSING);
        $this->invalidationService->shouldNotReceive('invalidate');
        $this->stripePaymentService->shouldNotReceive('createPaymentIntentWithClient');

        $this->assertSame('secret_old', $this->handler->handle('o_test')->clientSecret);
    }

    private function givenExistingPaymentIntent(int $amount, string $status = PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD): void
    {
        $this->stripePaymentService->shouldReceive('retrievePaymentIntentWithClient')->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_old',
            'amount' => $amount,
            'status' => $status,
            'client_secret' => 'secret_old',
        ]));
    }
}
