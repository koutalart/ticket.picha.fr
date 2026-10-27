<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\RequiredQuantityMode;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Exceptions\LiveChatRefundSelectionException;
use HiEvents\Services\Domain\LiveChat\Refund\LiveChatRefundAmountCalculator;
use Illuminate\Support\Collection;
use Tests\TestCase;

class LiveChatRefundAmountCalculatorTest extends TestCase
{
    private const TICKET = 1;

    private const OPTION = 2;

    private const VIP = 3;

    private LiveChatRefundAmountCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new LiveChatRefundAmountCalculator;
    }

    public function test_one_ticket_refunds_only_the_ticket_price(): void
    {
        $coverage = $this->calculator->coverage(
            collect([
                $this->item(10, self::TICKET, 101, price: 15.00, quantity: 1, fee: 0.99),
                $this->item(11, self::OPTION, 102, price: 0.49, quantity: 1),
            ]),
            $this->products(),
            collect([$this->attendee(1001, self::TICKET, 101)]),
        );

        $this->assertTrue($coverage->hasProtection);
        $this->assertSame(1, $coverage->maxTickets);
        $this->assertCount(1, $coverage->tickets);
        $this->assertSame('Awa Saïd', $coverage->tickets[0]->name);
        $this->assertSame('Plein tarif', $coverage->tickets[0]->category);

        $total = $this->calculator->total($this->calculator->select($coverage, [1001]));

        $this->assertSame(15.00, $total);
    }

    public function test_two_tickets_with_one_cancelled_leave_one_ticket_to_refund(): void
    {
        $coverage = $this->calculator->coverage(
            collect([
                $this->item(10, self::TICKET, 101, price: 15.00, quantity: 2, fee: 1.98),
                $this->item(11, self::OPTION, 102, price: 0.49, quantity: 2),
            ]),
            $this->products(),
            collect([
                $this->attendee(1001, self::TICKET, 101, AttendeeStatus::CANCELLED),
                $this->attendee(1002, self::TICKET, 101),
            ]),
        );

        $this->assertSame([1002], $coverage->tickets->map(fn ($ticket) => $ticket->attendeeId)->all());
        $this->assertSame(1, $coverage->maxTickets);
        $this->assertSame(15.00, $this->calculator->total($this->calculator->select($coverage, [1002])));

        $this->expectException(LiveChatRefundSelectionException::class);
        $this->calculator->select($coverage, [1001]);
    }

    public function test_per_ticket_protection_caps_the_number_of_tickets(): void
    {
        $coverage = $this->calculator->coverage(
            collect([
                $this->item(10, self::TICKET, 101, price: 15.00, quantity: 2),
                $this->item(12, self::VIP, 103, price: 40.00, quantity: 1),
                $this->item(11, self::OPTION, 102, price: 0.49, quantity: 2),
            ]),
            $this->products(),
            collect([
                $this->attendee(1001, self::TICKET, 101),
                $this->attendee(1002, self::TICKET, 101),
                $this->attendee(1003, self::VIP, 103),
            ]),
        );

        $this->assertCount(3, $coverage->tickets);
        $this->assertSame(2, $coverage->maxTickets);
        $this->assertSame(55.00, $this->calculator->total($this->calculator->select($coverage, [1001, 1003])));

        $this->expectException(LiveChatRefundSelectionException::class);
        $this->calculator->select($coverage, [1001, 1002, 1003]);
    }

    public function test_protection_per_order_covers_every_ticket(): void
    {
        $products = $this->products();
        $products->get(self::OPTION)->setRequiredQuantityMode(RequiredQuantityMode::PER_ORDER->name);

        $coverage = $this->calculator->coverage(
            collect([
                $this->item(10, self::TICKET, 101, price: 15.00, quantity: 3),
                $this->item(11, self::OPTION, 102, price: 0.49, quantity: 1),
            ]),
            $products,
            collect([
                $this->attendee(1001, self::TICKET, 101),
                $this->attendee(1002, self::TICKET, 101),
                $this->attendee(1003, self::TICKET, 101),
            ]),
        );

        $this->assertSame(3, $coverage->maxTickets);
        $this->assertSame(45.00, $this->calculator->total($this->calculator->select($coverage, [1001, 1002, 1003])));
    }

    public function test_optional_protection_ticked_before_payment_covers_the_whole_order(): void
    {
        $products = $this->products();
        $products->get(self::OPTION)
            ->setIsRequired(false)
            ->setRequiredQuantityMode(RequiredQuantityMode::PER_ORDER->name);

        $coverage = $this->calculator->coverage(
            collect([
                $this->item(10, self::TICKET, 101, price: 15.00, quantity: 2),
                $this->item(11, self::OPTION, 102, price: 0.49, quantity: 1),
            ]),
            $products,
            collect([
                $this->attendee(1001, self::TICKET, 101),
                $this->attendee(1002, self::TICKET, 101),
            ]),
        );

        $this->assertSame(2, $coverage->maxTickets);
    }

    public function test_optional_protection_sold_as_a_ticket_still_covers_its_quantity(): void
    {
        $products = $this->products();
        $products->get(self::OPTION)
            ->setIsRequired(false)
            ->setProductType(ProductType::TICKET->name)
            ->setRequiredQuantityMode(RequiredQuantityMode::PER_ORDER->name);

        $coverage = $this->calculator->coverage(
            collect([
                $this->item(10, self::TICKET, 101, price: 15.00, quantity: 2),
                $this->item(11, self::OPTION, 102, price: 0.49, quantity: 1),
            ]),
            $products,
            collect([
                $this->attendee(1001, self::TICKET, 101),
                $this->attendee(1002, self::TICKET, 101),
            ]),
        );

        $this->assertSame(1, $coverage->maxTickets);
    }

    public function test_order_without_protection_is_not_covered(): void
    {
        $coverage = $this->calculator->coverage(
            collect([$this->item(10, self::TICKET, 101, price: 15.00, quantity: 1)]),
            $this->products(),
            collect([$this->attendee(1001, self::TICKET, 101)]),
        );

        $this->assertFalse($coverage->hasProtection);
        $this->assertSame(0, $coverage->maxTickets);
    }

    public function test_tickets_of_another_order_or_unknown_ids_are_refused(): void
    {
        $coverage = $this->calculator->coverage(
            collect([
                $this->item(10, self::TICKET, 101, price: 15.00, quantity: 1),
                $this->item(11, self::OPTION, 102, price: 0.49, quantity: 1),
            ]),
            $this->products(),
            collect([$this->attendee(1001, self::TICKET, 101)]),
        );

        $this->expectException(LiveChatRefundSelectionException::class);
        $this->calculator->select($coverage, [1001, 9999]);
    }

    public function test_an_empty_selection_is_refused(): void
    {
        $coverage = $this->calculator->coverage(
            collect([
                $this->item(10, self::TICKET, 101, price: 15.00, quantity: 1),
                $this->item(11, self::OPTION, 102, price: 0.49, quantity: 1),
            ]),
            $this->products(),
            collect([$this->attendee(1001, self::TICKET, 101)]),
        );

        $this->expectException(LiveChatRefundSelectionException::class);
        $this->calculator->select($coverage, []);
    }

    public function test_mandatory_protection_covers_only_the_tickets_above_the_threshold_saved_at_purchase(): void
    {
        $products = $this->checkoutProducts();
        $coverage = $this->calculator->coverage(
            collect([
                $this->item(10, self::VIP, 201, price: 20.00, quantity: 1),
                $this->item(11, self::TICKET, 101, price: 10.00, quantity: 2),
                $this->item(12, self::OPTION, 102, price: 0.49, quantity: 1),
            ]),
            $products,
            collect([
                $this->attendee(1001, self::VIP, 201),
                $this->attendee(1002, self::TICKET, 101),
                $this->attendee(1003, self::TICKET, 101),
            ]),
            ['mandatory_quantity' => 1, 'optional_selected' => false, 'mandatory_above' => 15.00],
        );

        $this->assertSame([1001], $coverage->tickets->map(fn ($ticket) => $ticket->attendeeId)->all());
        $this->assertSame(1, $coverage->maxTickets);
        $this->assertSame(20.00, $this->calculator->total($this->calculator->select($coverage, [1001])));

        $this->expectException(LiveChatRefundSelectionException::class);
        $this->calculator->select($coverage, [1002]);
    }

    public function test_checked_box_on_a_mixed_order_covers_the_whole_order(): void
    {
        $coverage = $this->calculator->coverage(
            collect([
                $this->item(10, self::VIP, 201, price: 20.00, quantity: 1),
                $this->item(11, self::TICKET, 101, price: 10.00, quantity: 2),
                $this->item(12, self::OPTION, 102, price: 0.49, quantity: 2),
            ]),
            $this->checkoutProducts(),
            collect([
                $this->attendee(1001, self::VIP, 201),
                $this->attendee(1002, self::TICKET, 101),
                $this->attendee(1003, self::TICKET, 101),
            ]),
            ['mandatory_quantity' => 1, 'optional_selected' => true, 'mandatory_above' => 15.00],
        );

        $this->assertSame(3, $coverage->maxTickets);
        $this->assertSame(40.00, $this->calculator->total($this->calculator->select($coverage, [1001, 1002, 1003])));
    }

    public function test_a_ticket_whose_tariff_equals_the_threshold_is_not_covered_by_the_mandatory_part(): void
    {
        $coverage = $this->calculator->coverage(
            collect([
                $this->item(10, self::VIP, 201, price: 15.00, quantity: 1),
                $this->item(12, self::OPTION, 102, price: 0.49, quantity: 1),
            ]),
            $this->checkoutProducts(),
            collect([$this->attendee(1001, self::VIP, 201)]),
            ['mandatory_quantity' => 0, 'optional_selected' => false, 'mandatory_above' => 15.00],
        );

        $this->assertTrue($coverage->tickets->isEmpty());
    }

    /**
     * @return Collection<int, ProductDomainObject>
     */
    private function checkoutProducts(): Collection
    {
        return $this->products()->put(self::OPTION, (new ProductDomainObject)->setId(self::OPTION)->setTitle('Option sérénité')
            ->setProductType(ProductType::GENERAL->name)
            ->setIsRequired(false)
            ->setRequiredQuantityMode(RequiredQuantityMode::PER_ORDER->name)
            ->setIsRefundProtection(true));
    }

    /**
     * @return Collection<int, ProductDomainObject>
     */
    private function products(): Collection
    {
        return collect([
            self::TICKET => (new ProductDomainObject)->setId(self::TICKET)->setTitle('Plein tarif')
                ->setProductType(ProductType::TICKET->name),
            self::VIP => (new ProductDomainObject)->setId(self::VIP)->setTitle('VIP')
                ->setProductType(ProductType::TICKET->name),
            self::OPTION => (new ProductDomainObject)->setId(self::OPTION)->setTitle('Option sérénité / billet')
                ->setProductType(ProductType::GENERAL->name)
                ->setIsRequired(true)
                ->setRequiredQuantityMode(RequiredQuantityMode::PER_TICKET->name)
                ->setIsRefundProtection(true),
        ]);
    }

    private function item(int $id, int $productId, int $priceId, float $price, int $quantity, float $fee = 0.0): OrderItemDomainObject
    {
        $names = [self::TICKET => 'Plein tarif', self::VIP => 'VIP', self::OPTION => 'Option sérénité / billet'];

        return (new OrderItemDomainObject)
            ->setId($id)
            ->setProductId($productId)
            ->setProductPriceId($priceId)
            ->setItemName($names[$productId])
            ->setPrice($price)
            ->setQuantity($quantity)
            ->setTotalBeforeAdditions($price * $quantity)
            ->setTotalServiceFee($fee)
            ->setTotalGross($price * $quantity + $fee);
    }

    private function attendee(int $id, int $productId, int $priceId, AttendeeStatus $status = AttendeeStatus::ACTIVE): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)
            ->setId($id)
            ->setProductId($productId)
            ->setProductPriceId($priceId)
            ->setFirstName('Awa')
            ->setLastName('Saïd')
            ->setStatus($status->name);
    }
}
