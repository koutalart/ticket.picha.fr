<?php

declare(strict_types=1);

namespace Tests\Unit\Contact;

use HiEvents\DomainObjects\Enums\ContactImportField as F;
use HiEvents\Models\Organizer;
use HiEvents\Services\Domain\Contact\ContactImportService;
use HiEvents\Services\Domain\Contact\DTO\ContactImportOptionsDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class ContactImportTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private ContactImportService $service;

    private int $organizerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(ContactImportService::class);
        [$event] = $this->createEventWithProduct();
        $this->organizerId = $event->organizer_id;
    }

    public function test_brevo_export_headers_are_recognised(): void
    {
        $mapping = $this->service->suggestMapping(['CONTACT ID', 'LANDLINE_NUMBER', 'PRENOM', 'NOM', 'EMAIL', 'NATURE', 'SMS']);

        self::assertSame(['IGNORE', 'PHONE', 'FIRST_NAME', 'LAST_NAME', 'EMAIL', 'IGNORE', 'IGNORE'], $mapping);

        $withData = $this->service->suggestMapping(
            ['LANDLINE_NUMBER', 'PRENOM', 'EMAIL', 'SMS'],
            [['', 'Laura', 'laura@example.test', '0639 11 22 33'], ['', 'Mae', 'mae@example.test', '']],
        );
        self::assertSame(['IGNORE', 'FIRST_NAME', 'EMAIL', 'PHONE'], $withData);
    }

    public function test_full_name_split_keeps_uppercase_family_name(): void
    {
        self::assertSame(['Fatima', 'ABDALLAH'], ContactImportService::splitFullName('ABDALLAH Fatima'));
        self::assertSame(['Jean-Marc', 'Payet'], ContactImportService::splitFullName('Jean-Marc Payet'));
        self::assertSame(['Zaïna', null], ContactImportService::splitFullName('Zaïna'));
    }

    public function test_dry_run_counts_without_writing(): void
    {
        $result = $this->service->import($this->rows(), $this->importOptions(), dryRun: true);

        self::assertSame(3, $result->created_count);
        self::assertSame(0, $result->updated_count);
        self::assertSame(2, $result->rejected_count);
        self::assertSame(0, DB::table('contacts')->where('organizer_id', $this->organizerId)->count());
    }

    public function test_import_creates_merges_duplicates_and_tags(): void
    {
        $result = $this->service->import($this->rows(), $this->importOptions(tag: 'Clients 2024'), dryRun: false);

        self::assertSame(3, $result->created_count);
        $contacts = DB::table('contacts')->where('organizer_id', $this->organizerId)->orderBy('id')->get();
        self::assertSame(['fatima@example.test', 'kevin@example.test', null], $contacts->pluck('email')->all());
        self::assertSame('+262639123456', $contacts[0]->phone);
        self::assertSame('+262639000111', $contacts[2]->phone);
        self::assertSame('IMPORT', $contacts[0]->source);
        self::assertNotNull($contacts[0]->email_consent_at);
        self::assertSame('IMPORT', $contacts[0]->email_consent_source);
        self::assertSame(3, DB::table('contact_tag_assignments')->join('contact_tags', 'contact_tags.id', '=', 'contact_tag_id')->where('contact_tags.name', 'Clients 2024')->count());
        self::assertSame(1, DB::table('contact_tags')->where('organizer_id', $this->organizerId)->where('name', 'VIP')->count());
    }

    public function test_existing_contact_is_completed_and_an_unsubscribe_is_respected(): void
    {
        DB::table('contacts')->insert([
            'organizer_id' => $this->organizerId, 'email' => 'fatima@example.test', 'first_name' => 'Fatima déjà',
            'source' => 'ORDER', 'email_unsubscribed_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->import($this->rows(), $this->importOptions(), dryRun: false);

        self::assertSame(1, $result->updated_count);
        $contact = DB::table('contacts')->where('organizer_id', $this->organizerId)->where('email', 'fatima@example.test')->first();
        self::assertSame('Fatima déjà', $contact->first_name);
        self::assertSame('+262639123456', $contact->phone);
        self::assertNull($contact->email_consent_at);
    }

    public function test_revert_removes_only_contacts_created_by_the_import(): void
    {
        DB::table('contacts')->insert([
            'organizer_id' => $this->organizerId, 'email' => 'kevin@example.test', 'source' => 'ORDER',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = $this->service->import($this->rows(), $this->importOptions(), dryRun: false);

        $deleted = $this->service->revert($this->organizerId, $result->import_id);

        self::assertSame(2, $deleted);
        self::assertSame(['kevin@example.test'], DB::table('contacts')->where('organizer_id', $this->organizerId)->pluck('email')->all());
        self::assertSame('REVERTED', DB::table('contact_imports')->where('id', $result->import_id)->value('status'));
    }

    public function test_revert_of_another_organizer_import_does_nothing(): void
    {
        $result = $this->service->import($this->rows(), $this->importOptions(), dryRun: false);
        $otherOrganizer = Organizer::where('id', '!=', $this->organizerId)->value('id') ?? 0;

        self::assertNull($this->service->revert($otherOrganizer, $result->import_id));
        self::assertSame(3, DB::table('contacts')->where('organizer_id', $this->organizerId)->count());
    }

    private function rows(): array
    {
        return [
            ['ABDALLAH Fatima', 'Fatima@Example.test ', '0639 12 34 56', 'VIP'],
            ['Kevin Madi', 'kevin@example.test', '', ''],
            ['Doublon Fatima', 'fatima@example.test', '', 'VIP'],
            ['Sans Email', '', '0639 00 01 11', ''],
            ['Mauvais', 'pas-un-email', '', ''],
            ['Rien', '', 'n/a', ''],
        ];
    }

    private function importOptions(?string $tag = null): ContactImportOptionsDTO
    {
        return new ContactImportOptionsDTO(
            organizerId: $this->organizerId,
            userId: null,
            fileName: 'clients.csv',
            mapping: [F::FULL_NAME, F::EMAIL, F::PHONE, F::TAG],
            tagName: $tag,
            emailConsentDeclared: true,
            smsConsentDeclared: false,
        );
    }
}
