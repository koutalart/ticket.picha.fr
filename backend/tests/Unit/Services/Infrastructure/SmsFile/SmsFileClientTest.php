<?php

namespace Tests\Unit\Services\Infrastructure\SmsFile;

use HiEvents\DomainObjects\Enums\SmsFilePriority;
use HiEvents\DomainObjects\Enums\SmsFileState;
use HiEvents\Exceptions\SmsFileRejectedException;
use HiEvents\Exceptions\SmsFileUnavailableException;
use HiEvents\Services\Infrastructure\SmsFile\DTO\SmsFileMessageDTO;
use HiEvents\Services\Infrastructure\SmsFile\SmsFileClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsFileClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sms_file.enabled' => true,
            'services.sms_file.url' => 'http://file.picha.test/',
            'services.sms_file.key' => 'k-123',
        ]);
    }

    public function test_is_disabled_by_default_and_without_a_key(): void
    {
        config(['services.sms_file.enabled' => false]);
        $this->assertFalse(app(SmsFileClient::class)->isEnabled());

        config(['services.sms_file.enabled' => true, 'services.sms_file.key' => '']);
        $this->assertFalse(app(SmsFileClient::class)->isEnabled());

        config(['services.sms_file.key' => 'k-123']);
        $this->assertTrue(app(SmsFileClient::class)->isEnabled());
    }

    public function test_enqueue_returns_the_queue_answer(): void
    {
        Http::fake(['http://file.picha.test/v1/sms' => Http::response([
            'id' => 12, 'etat' => 'soumis', 'id_sms_picha' => 'abc', 'ref' => 'msg:5', 'doublon' => true,
        ], 201)]);

        $result = app(SmsFileClient::class)->enqueue($this->sms('msg:5'));

        $this->assertSame(12, $result->id);
        $this->assertSame(SmsFileState::SUBMITTED, $result->state);
        $this->assertSame('abc', $result->providerMessageId);
        $this->assertTrue($result->duplicate);
        Http::assertSent(fn (Request $request) => $request->hasHeader('x-file-key', 'k-123')
            && $request->data() === [
                'organisateur' => 4,
                'from' => '+262639000000',
                'to' => '+262639123456',
                'texte' => 'Bonjour',
                'priorite' => 'urgent',
                'ref' => 'msg:5',
            ]);
    }

    public function test_enqueue_many_sends_one_list(): void
    {
        Http::fake(['http://file.picha.test/v1/sms' => Http::response([
            ['id' => 1, 'etat' => 'en_file', 'id_sms_picha' => null],
            ['id' => 2, 'etat' => 'en_file', 'id_sms_picha' => null],
        ], 200)]);

        $results = app(SmsFileClient::class)->enqueueMany([$this->sms('1:1'), $this->sms('1:2')]);

        $this->assertSame([1, 2], array_map(fn ($result) => $result->id, $results));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => array_is_list($request->data()) && count($request->data()) === 2);
    }

    public function test_invalid_request_is_rejected(): void
    {
        Http::fake(['*' => Http::response(['erreur' => 'texte vide'], 422)]);

        $this->expectException(SmsFileRejectedException::class);
        $this->expectExceptionMessage('texte vide');
        app(SmsFileClient::class)->enqueue($this->sms('msg:1'));
    }

    public function test_bad_key_or_server_error_is_unavailable(): void
    {
        Http::fake(['*' => Http::response(['erreur' => 'cle x-file-key absente ou invalide'], 401)]);

        $this->expectException(SmsFileUnavailableException::class);
        app(SmsFileClient::class)->find(3);
    }

    public function test_connection_failure_is_unavailable(): void
    {
        Http::fake(['*' => Http::failedConnection('Connection refused')]);

        $this->expectException(SmsFileUnavailableException::class);
        app(SmsFileClient::class)->enqueue($this->sms('msg:1'));
    }

    public function test_answer_with_a_wrong_count_is_unavailable(): void
    {
        Http::fake(['*' => Http::response([['id' => 1, 'etat' => 'en_file']], 200)]);

        $this->expectException(SmsFileUnavailableException::class);
        app(SmsFileClient::class)->enqueueMany([$this->sms('1:1'), $this->sms('1:2')]);
    }

    private function sms(string $ref): SmsFileMessageDTO
    {
        return new SmsFileMessageDTO(
            organizerId: 4,
            from: '+262639000000',
            to: '+262639123456',
            text: 'Bonjour',
            priority: SmsFilePriority::URGENT,
            ref: $ref,
        );
    }
}
