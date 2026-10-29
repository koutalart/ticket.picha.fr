<?php

namespace Tests\Unit\Services\Domain\Sms\CheckInWelcome;

use HiEvents\Http\Request\Sms\UpdateOrganizerSmsSettingsRequest;
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

class SmsCheckInWelcomeOrganizerToneTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const INNOCENT_TEMPLATES = <<<'TXT'
{prenom}, te voilà au 5/5 ! Content de te voir, la nuit d'Halloween commence 🎃
Bienvenue {prenom} ! Ce soir plus personne n'est innocent, profite à fond 👻
{prenom}, t'es dans la place ! Va voir les déguisements, la soirée ne fait que commencer 🎃
{prenom}, bienvenue chez les djinns ! Profite de chaque son ce soir 🎧
{prenom}, ton entrée est validée. Le D et Terrell arrivent, garde des forces 🔥
Bienvenue au 5/5 {prenom} ! Ce soir on danse jusqu'au bout de la nuit 🎃
{prenom}, t'as passé la porte, maintenant place aux frissons 👻 Bonne soirée !
Karibu {prenom} ! La nuit d'Halloween est à toi, profite bien 🎃
{prenom}, enfin là ! Sweety, Asskaboy et BVNG'Z t'attendent sur la piste 🎧
Bienvenue {prenom} ! Si tu croises un djinn ce soir, c'est normal 😈 Bonne soirée !
{prenom}, merci d'être là ce soir. Profite, c'est ta soirée 🎃
{prenom}, la soirée t'attendait. Lâche-toi, c'est Halloween au 5/5 🔥
TXT;

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

    public function test_innocent_templates_follow_the_organizer_rules(): void
    {
        $templates = SmsCheckInWelcomeMessageGenerator::templatesFromText(self::INNOCENT_TEMPLATES);

        $this->assertCount(12, $templates);
        foreach ($templates as $template) {
            $this->assertNull(SmsCheckInWelcomeMessageGenerator::templateViolation($template), $template);
        }
    }

    #[DataProvider('invalidTemplates')]
    public function test_invalid_organizer_templates_are_refused(string $template): void
    {
        $this->assertNotNull(SmsCheckInWelcomeMessageGenerator::templateViolation($template));
    }

    public static function invalidTemplates(): array
    {
        return [
            'no first name' => ['Bienvenue au 5/5 ! 🎃'],
            'two emojis' => ['{prenom}, bienvenue 🎃👻'],
            'too long' => ['{prenom}, '.str_repeat('bienvenue ', 16)],
            'long dash' => ['{prenom} — bienvenue !'],
            'bullet' => ['{prenom} • bienvenue !'],
            'heyy' => ['Heyy {prenom}, bienvenue !'],
            'picha' => ['{prenom}, merci d\'avoir pris ton billet sur PICHA !'],
            'price' => ['{prenom}, le cocktail est à 5 euros ce soir'],
            'euro sign' => ['{prenom}, cocktail à 5€'],
            'link' => ['{prenom}, les photos sur https://innocent976.yt'],
            'equipe' => ['{prenom}, toute l\'équipe te souhaite la bienvenue'],
            'scanner' => ['{prenom}, ton billet vient d\'être scanné !'],
            'bip' => ['Bip ! Bienvenue {prenom}'],
            'qr' => ['{prenom}, ton QR code est validé'],
            'signature' => ['Bienvenue {prenom} ! Signé Innocent'],
        ];
    }

    public function test_settings_request_reports_the_line_of_the_first_invalid_template(): void
    {
        $errors = [];
        $fail = function (string $message) use (&$errors) {
            $errors[] = $message;
        };

        UpdateOrganizerSmsSettingsRequest::welcomeTemplatesRule(
            'checkin_welcome_sms_templates',
            "Bienvenue {prenom} ! 🎃\n\nToute l'équipe te dit bienvenue {prenom}",
            $fail,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('3', $errors[0]);

        $errors = [];
        UpdateOrganizerSmsSettingsRequest::welcomeTemplatesRule('checkin_welcome_sms_templates', self::INNOCENT_TEMPLATES, $fail);
        $this->assertSame([], $errors);
    }

    public function test_organizer_tone_is_appended_to_the_prompt_and_allows_accents_and_one_emoji(): void
    {
        $this->claude->shouldReceive('askWithUsage')->once()->withArgs(
            fn (string $system) => str_ends_with($system, 'Tu es Innocent, tu accueilles toi-même.')
        )->andReturn($this->answer('Nadia, bienvenue chez les djinns ! Lâche-toi, c\'est ta fête 🎃'));

        $message = $this->generator->generate('Nadia', 'Halloween au 5/5', 'Tu es Innocent, tu accueilles toi-même.', []);

        $this->assertSame(SmsCheckInWelcomeMessageGenerator::SOURCE_MODEL, $message->source);
        $this->assertSame('Nadia, bienvenue chez les djinns ! Lâche-toi, c\'est ta fête 🎃', $message->content);
    }

    #[DataProvider('invalidOrganizerAnswers')]
    public function test_invalid_organizer_answer_falls_back_to_the_organizer_templates(string $answer): void
    {
        $this->claude->shouldReceive('askWithUsage')->andReturn($this->answer($answer));

        $message = $this->generator->generate(
            'Nadia',
            'Halloween au 5/5',
            'Tu es Innocent.',
            SmsCheckInWelcomeMessageGenerator::templatesFromText(self::INNOCENT_TEMPLATES),
        );

        $this->assertSame(SmsCheckInWelcomeMessageGenerator::SOURCE_TEMPLATE, $message->source);
        $this->assertStringContainsString('Nadia', $message->content);
        $this->assertSame(1, SmsCheckInWelcomeMessageGenerator::countEmojis($message->content));
    }

    public static function invalidOrganizerAnswers(): array
    {
        return [
            'two emojis' => ['Nadia, bienvenue 🎃👻'],
            'scanner' => ['Nadia, le scanner a bipé, bienvenue !'],
            'equipe' => ['Nadia, toute l\'équipe te souhaite la bienvenue'],
            'no first name' => ['Bienvenue chez les djinns ! 🎃'],
            'two lines' => ["Nadia, bienvenue !\nInnocent"],
        ];
    }

    public function test_templates_only_skip_the_model(): void
    {
        $this->claude->shouldNotReceive('askWithUsage');

        $message = $this->generator->generate('Nadia', 'Halloween', null, ['Karibu {prenom} ! 🎃']);

        $this->assertSame('Karibu Nadia ! 🎃', $message->content);
    }

    public function test_generic_tone_stays_gsm7_without_organizer_settings(): void
    {
        $this->claude->shouldReceive('askWithUsage')->andReturn($this->answer('Nadia, bienvenue à Halloween 🎃'));

        $message = $this->generator->generate('Nadia', 'Halloween');

        $this->assertTrue(SmsCheckInWelcomeMessageGenerator::isGsm7($message->content));
    }

    private function answer(string $message): ClaudeStructuredAnswerDTO
    {
        return new ClaudeStructuredAnswerDTO(data: ['message' => $message], model: 'claude-haiku-5-5', inputTokens: 10, outputTokens: 5);
    }
}
