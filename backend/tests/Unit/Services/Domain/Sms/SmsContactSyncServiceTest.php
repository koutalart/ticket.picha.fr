<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsCustomerContactDTO;
use HiEvents\Services\Domain\Sms\SmsContactSyncService;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SmsContactSyncServiceTest extends TestCase
{
    private OrganizerSmsSettingRepositoryInterface|MockInterface $settingsRepository;

    private OrganizerRepositoryInterface|MockInterface $organizerRepository;

    private SmsCustomerDirectoryRepositoryInterface|MockInterface $directoryRepository;

    private HttpSmsClient|MockInterface $client;

    private SmsContactSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsRepository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);
        $this->organizerRepository = Mockery::mock(OrganizerRepositoryInterface::class);
        $this->directoryRepository = Mockery::mock(SmsCustomerDirectoryRepositoryInterface::class);
        $this->client = Mockery::mock(HttpSmsClient::class);

        $this->service = new SmsContactSyncService(
            $this->settingsRepository,
            $this->organizerRepository,
            $this->directoryRepository,
            $this->client,
            new NullLogger,
        );
    }

    public function test_does_nothing_without_configured_gateway(): void
    {
        $this->settingsRepository->shouldReceive('findWhere')->andReturn(collect([
            (new OrganizerSmsSettingDomainObject)->setOrganizerId(4)->setApiKey(null),
        ]));
        $this->client->shouldNotReceive('listContacts');

        $result = $this->service->syncAll();

        $this->assertSame(0, $result->accounts);
    }

    public function test_creates_missing_contacts_with_normalized_phone_and_latest_name(): void
    {
        $this->givenSettings([[4, 'uk_a']]);
        $this->givenCustomers(4, [
            ['0639 12 34 56', 'Awa', 'Madi', 'AWA@example.com'],
            ['+262639123456', 'Ancien', 'Nom', null],
            ['not a phone', 'Sans', 'Numero', null],
            ['0639654321', '', '', null],
        ]);
        $this->client->shouldReceive('listContacts')->with('uk_a', 0, 100)->andReturn([]);

        $this->client->shouldReceive('createContacts')->once()->with('uk_a', [[
            'name' => 'Awa Madi',
            'phone_numbers' => ['+262639123456'],
            'emails' => ['awa@example.com'],
            'properties' => ['picha_sync' => '1', 'picha_organizers' => '4'],
        ]]);

        $result = $this->service->syncAll();

        $this->assertSame(1, $result->created);
        $this->assertSame(0, $result->failed);
    }

    public function test_dry_run_counts_changes_without_writing(): void
    {
        $this->givenSettings([[4, 'uk_a']]);
        $this->givenCustomers(4, [
            ['0639123456', 'Awa', 'Madi', null],
            ['0639000001', 'Ben', 'Ali', null],
        ]);
        $this->client->shouldReceive('listContacts')->andReturn([
            $this->managedContact('c1', 'Awa M', '+262639123456', '4'),
        ]);
        $this->client->shouldNotReceive('createContacts');
        $this->client->shouldNotReceive('updateContact');

        $result = $this->service->syncAll(dryRun: true);

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->updated);
    }

    public function test_never_overrides_a_manually_created_contact(): void
    {
        $this->givenSettings([[4, 'uk_a']]);
        $this->givenCustomers(4, [['0639123456', 'Awa', 'Madi', null]]);
        $this->client->shouldReceive('listContacts')->andReturn([
            ['id' => 'c1', 'name' => 'Tata Awa', 'phone_numbers' => ['+262639123456'], 'emails' => [], 'properties' => []],
        ]);
        $this->client->shouldNotReceive('createContacts');
        $this->client->shouldNotReceive('updateContact');

        $result = $this->service->syncAll();

        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->updated);
    }

    public function test_updates_managed_contact_only_when_it_changed(): void
    {
        $this->givenSettings([[4, 'uk_a']]);
        $this->givenCustomers(4, [
            ['0639123456', 'Awa', 'Madi', null],
            ['0639000001', 'Ben', 'Ali', null],
        ]);
        $this->client->shouldReceive('listContacts')->andReturn([
            $this->managedContact('c1', 'Awa M', '+262639123456', '4'),
            $this->managedContact('c2', 'Ben Ali', '+262639000001', '4'),
        ]);

        $this->client->shouldReceive('updateContact')->once()->with('uk_a', 'c1', Mockery::on(
            fn (array $contact) => $contact['name'] === 'Awa Madi'
        ));
        $this->client->shouldNotReceive('createContacts');

        $result = $this->service->syncAll();

        $this->assertSame(1, $result->updated);
        $this->assertSame(1, $result->unchanged);
    }

    public function test_shared_account_labels_contacts_with_organizer_names(): void
    {
        $this->givenSettings([[4, 'uk_shared'], [9, 'uk_shared']]);
        $this->givenCustomers(4, [['0639123456', 'Awa', 'Madi', null]]);
        $this->givenCustomers(9, [['0639123456', 'Awa', 'Madi', null]]);
        $this->client->shouldReceive('listContacts')->andReturn([]);

        $this->client->shouldReceive('createContacts')->once()->with('uk_shared', Mockery::on(
            fn (array $contacts) => count($contacts) === 1
                && $contacts[0]['name'] === 'Awa Madi (Innocent Event, Konka Wawa)'
                && $contacts[0]['properties']['picha_organizers'] === '4,9'
        ));

        $this->service->syncAll();
    }

    public function test_separate_accounts_only_receive_their_own_customers(): void
    {
        $this->givenSettings([[4, 'uk_a'], [9, 'uk_b']]);
        $this->givenCustomers(4, [['0639123456', 'Awa', 'Madi', null]]);
        $this->givenCustomers(9, [['0639000001', 'Ben', 'Ali', null]]);
        $this->client->shouldReceive('listContacts')->andReturn([]);

        $this->client->shouldReceive('createContacts')->once()->with('uk_a', Mockery::on(
            fn (array $contacts) => $contacts[0]['phone_numbers'] === ['+262639123456'] && $contacts[0]['name'] === 'Awa Madi'
        ));
        $this->client->shouldReceive('createContacts')->once()->with('uk_b', Mockery::on(
            fn (array $contacts) => $contacts[0]['phone_numbers'] === ['+262639000001']
        ));

        $result = $this->service->syncAll();

        $this->assertSame(2, $result->accounts);
    }

    public function test_rejected_batch_is_retried_contact_by_contact(): void
    {
        $this->givenSettings([[4, 'uk_a']]);
        $this->givenCustomers(4, [
            ['0639123456', 'Awa', 'Madi', null],
            ['0639000001', 'Ben', 'Ali', null],
        ]);
        $this->client->shouldReceive('listContacts')->andReturn([]);

        $this->client->shouldReceive('createContacts')
            ->with('uk_a', Mockery::on(fn (array $contacts) => count($contacts) === 2))
            ->andThrow(new SmsGatewayException('invalid', 422));
        $this->client->shouldReceive('createContacts')
            ->with('uk_a', Mockery::on(fn (array $contacts) => count($contacts) === 1 && $contacts[0]['name'] === 'Awa Madi'))
            ->andThrow(new SmsGatewayException('invalid', 422));
        $this->client->shouldReceive('createContacts')
            ->with('uk_a', Mockery::on(fn (array $contacts) => count($contacts) === 1 && $contacts[0]['name'] === 'Ben Ali'));

        $result = $this->service->syncAll();

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->failed);
    }

    public function test_gateway_outage_on_one_account_does_not_stop_the_others(): void
    {
        $this->givenSettings([[4, 'uk_a'], [9, 'uk_b']]);
        $this->givenCustomers(4, [['0639123456', 'Awa', 'Madi', null]]);
        $this->givenCustomers(9, [['0639000001', 'Ben', 'Ali', null]]);
        $this->client->shouldReceive('listContacts')->with('uk_a', 0, 100)->andThrow(new SmsGatewayException('down', 503));
        $this->client->shouldReceive('listContacts')->with('uk_b', 0, 100)->andReturn([]);
        $this->client->shouldReceive('createContacts')->once()->with('uk_b', Mockery::any());

        $result = $this->service->syncAll();

        $this->assertSame(1, $result->accounts);
        $this->assertSame(1, $result->created);
    }

    /**
     * @param  array<int, array{0: int, 1: string}>  $settings
     */
    private function givenSettings(array $settings): void
    {
        $this->settingsRepository->shouldReceive('findWhere')->andReturn(collect(array_map(
            fn (array $setting) => (new OrganizerSmsSettingDomainObject)->setOrganizerId($setting[0])->setApiKey($setting[1]),
            $settings,
        )));

        $names = [4 => 'Innocent Event', 9 => 'Konka Wawa'];
        $this->organizerRepository->shouldReceive('findWhereIn')->andReturn(collect(array_map(
            fn (array $setting) => (new OrganizerDomainObject)->setId($setting[0])->setName($names[$setting[0]]),
            $settings,
        ))->unique(fn (OrganizerDomainObject $organizer) => $organizer->getId())->values());
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string, 3: ?string}>  $customers
     */
    private function givenCustomers(int $organizerId, array $customers): void
    {
        $this->directoryRepository->shouldReceive('findCustomersWithPhone')->with($organizerId)->andReturn(collect(array_map(
            fn (array $customer) => new SmsCustomerContactDTO(
                organizerId: $organizerId,
                phone: $customer[0],
                firstName: $customer[1],
                lastName: $customer[2],
                email: $customer[3],
                orderedAt: '2026-10-21 10:00:00',
            ),
            $customers,
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function managedContact(string $id, string $name, string $phone, string $organizers): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'phone_numbers' => [$phone],
            'emails' => [],
            'properties' => ['picha_sync' => '1', 'picha_organizers' => $organizers],
        ];
    }
}
