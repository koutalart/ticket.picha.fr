<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class MarkRefundProtectionProductCommandTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    public function test_product_is_marked_only_when_its_title_matches(): void
    {
        [, $product] = $this->createEventWithProduct();
        DB::table('products')->where('id', $product->id)->update(['title' => '🛡️ Option Sérénité / billet']);

        $this->artisan('live-chat:refund-protection', ['product_id' => $product->id, '--title-contains' => 'autre'])
            ->assertFailed();
        $this->assertFalse((bool) DB::table('products')->where('id', $product->id)->value('is_refund_protection'));

        $this->artisan('live-chat:refund-protection', ['product_id' => $product->id])->assertFailed();
        $this->artisan('live-chat:refund-protection', ['product_id' => 999999999, '--title-contains' => 'sérénité'])->assertFailed();

        $this->artisan('live-chat:refund-protection', ['product_id' => $product->id, '--title-contains' => 'sérénité'])
            ->assertSuccessful();
        $this->assertTrue((bool) DB::table('products')->where('id', $product->id)->value('is_refund_protection'));

        $this->artisan('live-chat:refund-protection', ['product_id' => $product->id, '--title-contains' => 'sérénité', '--off' => true])
            ->assertSuccessful();
        $this->assertFalse((bool) DB::table('products')->where('id', $product->id)->value('is_refund_protection'));
    }
}
