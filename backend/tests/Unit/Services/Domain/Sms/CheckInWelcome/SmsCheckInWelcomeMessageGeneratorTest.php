<?php

namespace Tests\Unit\Services\Domain\Sms\CheckInWelcome;

use HiEvents\Exceptions\EventAssistantFailedException;
use HiEvents\Services\Domain\Sms\CheckInWelcome\SmsCheckInWelcomeMessageGenerator;
use HiEvents\Services\Infrastructure\AI\ClaudeStructuredOutputClient;
use HiEvents\Services\Infrastructure\AI\DTO\ClaudeStructuredAnswerDTO;
use Illuminate\Config\Repository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SmsCheckInWelcomeMessageGeneratorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ClaudeStructuredOutputClient|MockInterface $claude;

    private SmsCheckInWelcomeMessageGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $this->claude->shouldReceive('isConfigured')->andReturnTrue()->byDefault();
        $this->generator = new SmsCheckInWelcomeMessageGenerator(
            $this->claude,
            new Repository(['services' => ['live_chat' => ['model' => 'claude-haiku-5-5']]]),
            new NullLogger,
        );
    }

    public function test_uses_a_valid_model_answer_with_a_short_timeout(): void
    {
        $this->claude->shouldReceive('askWithUsage')->once()->withArgs(
            fn (string $system, array $content, array $schema, array $tools = [], ?string $model = null, ?int $maxTokens = null, ?float $timeout = null) => $model === 'claude-haiku-5-5' && $timeout === 3.0
        )->andReturn($this->answer('Nadia, le scanner a bipé, Halloween peut commencer ! Bienvenue à toi.'));

        $message = $this->generator->generate('Nadia', 'Halloween');

        $this->assertSame(SmsCheckInWelcomeMessageGenerator::SOURCE_MODEL, $message->source);
        $this->assertSame('Nadia, le scanner a bipé, Halloween peut commencer ! Bienvenue à toi.', $message->content);
    }

    #[DataProvider('invalidAnswers')]
    public function test_falls_back_to_a_template_on_an_invalid_model_answer(string $answer): void
    {
        $this->claude->shouldReceive('askWithUsage')->andReturn($this->answer($answer));

        $message = $this->generator->generate('Nadia', 'Halloween');

        $this->assertSame(SmsCheckInWelcomeMessageGenerator::SOURCE_TEMPLATE, $message->source);
        $this->assertTrue(SmsCheckInWelcomeMessageGenerator::isValid($message->content, 'Nadia', 'Halloween'));
        $this->assertStringContainsString('Nadia', $message->content);
    }

    public static function invalidAnswers(): array
    {
        return [
            'empty' => [''],
            'too long' => ['Nadia, '.str_repeat('bienvenue ', 20)],
            'forbidden dash' => ['Nadia — bienvenue à Halloween !'],
            'forbidden bullet' => ['Nadia • bienvenue à Halloween !'],
            'heeyy' => ['Heeyy Nadia, bienvenue à Halloween !'],
            'price' => ['Nadia, bienvenue ! Le cocktail est à 5 euros.'],
            'link' => ['Nadia, bienvenue ! Infos sur https://ticket.picha.fr'],
            'ai' => ['Nadia, ton assistante IA te souhaite la bienvenue.'],
            'no first name' => ['Bienvenue à Halloween, profite bien !'],
            'two lines' => ["Nadia, bienvenue !\nAnicka"],
        ];
    }

    public function test_falls_back_to_a_template_when_the_model_fails_or_times_out(): void
    {
        $this->claude->shouldReceive('askWithUsage')->andThrow(new EventAssistantFailedException('timeout'));

        $message = $this->generator->generate('Nadia', 'Halloween');

        $this->assertSame(SmsCheckInWelcomeMessageGenerator::SOURCE_TEMPLATE, $message->source);
        $this->assertStringContainsString('Nadia', $message->content);
    }

    public function test_falls_back_to_a_template_without_model_key(): void
    {
        $this->claude->shouldReceive('isConfigured')->andReturnFalse();
        $this->claude->shouldNotReceive('askWithUsage');

        $this->assertSame(SmsCheckInWelcomeMessageGenerator::SOURCE_TEMPLATE, $this->generator->generate('Nadia', 'Halloween')->source);
    }

    public function test_every_template_follows_the_rules(): void
    {
        $this->assertGreaterThanOrEqual(25, count(SmsCheckInWelcomeMessageGenerator::TEMPLATES));
        $this->assertSame(count(SmsCheckInWelcomeMessageGenerator::TEMPLATES), count(array_unique(SmsCheckInWelcomeMessageGenerator::TEMPLATES)));

        foreach (SmsCheckInWelcomeMessageGenerator::TEMPLATES as $template) {
            $this->assertStringContainsString('{prenom}', $template);
            $message = SmsCheckInWelcomeMessageGenerator::render($template, 'Maximilienne', 'Konka Wawa 25 ans');
            $this->assertTrue(
                SmsCheckInWelcomeMessageGenerator::isValid($message, 'Maximilienne', 'Konka Wawa 25 ans'),
                $template,
            );
        }
    }

    public function test_long_event_name_still_gives_a_short_message(): void
    {
        $message = $this->generator->fromTemplate('Nadia', str_repeat('Très longue soirée ', 10));

        $this->assertLessThanOrEqual(SmsCheckInWelcomeMessageGenerator::MAX_LENGTH, mb_strlen($message));
        $this->assertStringContainsString('Nadia', $message);
    }

    public function test_names_are_made_gsm_friendly(): void
    {
        $this->assertSame('Fete des djinns', SmsCheckInWelcomeMessageGenerator::toGsm('Fête des djinns'));
        $this->assertSame("L'été 976", SmsCheckInWelcomeMessageGenerator::toGsm('L’été 976 🎉'));
    }

    private function answer(string $message): ClaudeStructuredAnswerDTO
    {
        return new ClaudeStructuredAnswerDTO(data: ['message' => $message], model: 'claude-haiku-5-5', inputTokens: 10, outputTokens: 5);
    }
}
