<?php

namespace Tests\Unit\Services\Infrastructure\UneAnicka;

use HiEvents\Services\Infrastructure\UneAnicka\UneAnickaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;
use Tests\TestCase;

class UneAnickaClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.une_anicka' => ['enabled' => true, 'url' => 'http://172.22.0.1:8097', 'key' => 'cle', 'timeout_seconds' => 4]]);
    }

    private function client(): UneAnickaClient
    {
        return new UneAnickaClient(app('config'), new NullLogger);
    }

    public function test_router_holds_the_reply(): void
    {
        Http::fake(['172.22.0.1:8097/*' => Http::response(['ok' => false, 'regle' => 'R3', 'raison' => 'session-claude a écrit à 08:09'])]);

        $decision = $this->client()->decide('billetterie-attente', '+262639581657', 'sms', 'Bonne question', 8);

        $this->assertFalse($decision->allowed);
        $this->assertSame('R3', $decision->rule);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://172.22.0.1:8097/v1/une-anicka/decider'
            && $request->hasHeader('x-file-key', 'cle')
            && $request['systeme'] === 'billetterie-attente'
            && $request['contact'] === '+262639581657'
            && $request['genre'] === 'auto'
            && $request['organisateur'] === 8);
    }

    public function test_router_allows_the_reply(): void
    {
        Http::fake(['172.22.0.1:8097/*' => Http::response(['ok' => true, 'regle' => 'OK', 'raison' => 'permis'])]);

        $this->assertTrue($this->client()->decide('billetterie-chat', 'chat:abc', 'chat')->allowed);
    }

    public function test_unreachable_or_broken_router_never_blocks(): void
    {
        Http::fake(['172.22.0.1:8097/*' => fn () => throw new ConnectionException('timeout')]);
        $this->assertTrue($this->client()->decide('billetterie-chat', 'chat:abc', 'chat')->allowed);

        Http::fake(['172.22.0.1:8097/*' => Http::response('erreur', 500)]);
        $this->assertTrue($this->client()->decide('billetterie-chat', 'chat:abc', 'chat')->allowed);
    }

    public function test_disabled_router_is_not_called(): void
    {
        config(['services.une_anicka.enabled' => false]);
        Http::fake();

        $this->assertTrue($this->client()->decide('billetterie-chat', 'chat:abc', 'chat')->allowed);
        Http::assertNothingSent();
    }

    public function test_human_reply_is_sent_as_human(): void
    {
        Http::fake(['172.22.0.1:8097/*' => Http::response(['ok' => true, 'regle' => 'R1', 'raison' => 'humain'])]);

        $this->client()->decide('humain-chatwoot', 'chat:abc', 'chat', 'Bonjour', 8, kind: 'humain');

        Http::assertSent(fn (Request $request) => $request['genre'] === 'humain');
    }
}
