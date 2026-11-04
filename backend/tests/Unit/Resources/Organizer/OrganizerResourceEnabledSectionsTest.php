<?php

namespace Tests\Unit\Resources\Organizer;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Models\Account;
use HiEvents\Models\Organizer;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Resources\Organizer\OrganizerResource;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class OrganizerResourceEnabledSectionsTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private function resolve(OrganizerDomainObject $organizer): array
    {
        return (new OrganizerResource($organizer))->toArray(Request::create('/'));
    }

    private function organizer(array|string|null $sections): OrganizerDomainObject
    {
        return (new OrganizerDomainObject)
            ->setId(17)
            ->setName('El Djine Officiel')
            ->setEmail('eldjine@example.test')
            ->setTimezone('Indian/Mayotte')
            ->setEnabledSections($sections);
    }

    public function test_enabled_sections_is_null_by_default(): void
    {
        $data = $this->resolve($this->organizer(null));

        $this->assertArrayHasKey('enabled_sections', $data);
        $this->assertNull($data['enabled_sections']);
    }

    public function test_enabled_sections_is_exposed_as_a_list(): void
    {
        $this->assertSame(
            ['reports', 'qr-codes', 'publications'],
            $this->resolve($this->organizer(['reports', 'qr-codes', 'publications']))['enabled_sections'],
        );
    }

    public function test_enabled_sections_stored_as_json_string_is_decoded(): void
    {
        $this->assertSame(
            ['publications'],
            $this->resolve($this->organizer('["publications"]'))['enabled_sections'],
        );
    }

    public function test_command_round_trips_through_the_database(): void
    {
        $user = $this->createUserWithAccount();
        /** @var Account $account */
        $account = $user->accounts()->first();

        $organizer = Organizer::create([
            'account_id' => $account->id,
            'name' => 'El Djine Officiel',
            'email' => 'eldjine@example.test',
            'currency' => 'EUR',
            'timezone' => 'Indian/Mayotte',
        ]);

        $this->artisan('organizer:sections', ['organizer' => $organizer->id, '--only' => 'publications,qr-codes,reports'])
            ->assertSuccessful();

        $repository = $this->app->make(OrganizerRepositoryInterface::class);
        $this->assertSame(
            ['reports', 'qr-codes', 'publications'],
            $this->resolve($repository->findById($organizer->id))['enabled_sections'],
        );

        $this->artisan('organizer:sections', ['organizer' => $organizer->id, '--all' => true])
            ->assertSuccessful();

        $this->assertNull($this->resolve($repository->findById($organizer->id))['enabled_sections']);
    }
}
