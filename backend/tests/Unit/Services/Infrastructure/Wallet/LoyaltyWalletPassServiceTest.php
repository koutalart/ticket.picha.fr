<?php

namespace Tests\Unit\Services\Infrastructure\Wallet;

use HiEvents\DomainObjects\LoyaltyCardDomainObject;
use HiEvents\DomainObjects\LoyaltyProgramDomainObject;
use HiEvents\Services\Infrastructure\Wallet\LoyaltyWalletPassService;
use Tests\TestCase;

class LoyaltyWalletPassServiceTest extends TestCase
{
    private LoyaltyWalletPassService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mobile-pass.apple.type_identifier' => 'pass.fr.picha.test',
            'mobile-pass.apple.team_identifier' => 'TEAMTEST01',
            'mobile-pass.apple.webservice.secret' => null,
            'mobile-pass.apple.webservice.host' => null,
            'mobile-pass.google.issuer_id' => '3388000000000000000',
            'app.url' => 'http://localhost',
        ]);

        $this->service = app(LoyaltyWalletPassService::class);
    }

    public function test_apple_pass_carries_locations_beacon_and_lock_screen_texts(): void
    {
        $data = $this->service->buildApplePass($this->program(), $this->card(), 'La Terrasse de la Barge')->data();

        $this->assertSame('pass.fr.picha.test', $data['passTypeIdentifier']);
        $this->assertSame('picha-fid-AbCdEfGhIjKlMnOpQrStUvWxYz012345', $data['serialNumber']);
        $this->assertCount(2, $data['locations']);
        $this->assertSame(-12.7806, $data['locations'][0]['latitude']);
        $this->assertSame('Un café vous attend à la Barge', $data['locations'][0]['relevantText']);
        $this->assertArrayNotHasKey('relevantText', $data['locations'][1]);
        $this->assertSame(150, $data['maxDistance']);
        $this->assertSame('F7826DA6-4FA2-4E98-8024-BC5B71E0893E', $data['beacons'][0]['proximityUUID']);
        $this->assertSame(1, $data['beacons'][0]['major']);
        $this->assertSame('Montrez votre carte au comptoir', $data['beacons'][0]['relevantText']);
        $this->assertSame('PICHAFID:AbCdEfGhIjKlMnOpQrStUvWxYz012345', $data['barcodes'][0]['message']);
        $this->assertSame('4 / 9', $data['storeCard']['primaryFields'][0]['value']);
    }

    public function test_google_class_and_object_carry_the_merchant_locations(): void
    {
        $class = $this->service->buildGoogleClass($this->program(), 'La Terrasse de la Barge')->payload();
        $object = $this->service->buildGoogleObject($this->program(), $this->card())->payload();

        $this->assertSame('3388000000000000000.picha-fidelite-3', $class['id']);
        $this->assertCount(2, $class['merchantLocations']);
        $this->assertSame(['latitude' => -12.7806, 'longitude' => 45.2279], $class['merchantLocations'][0]);
        $this->assertCount(2, $object['merchantLocations']);
        $this->assertSame('4 / 9', $object['loyaltyPoints']['balance']['string']);
        $this->assertSame('PICHAFID:AbCdEfGhIjKlMnOpQrStUvWxYz012345', $object['barcode']['value']);
    }

    public function test_reads_the_public_id_from_a_scanned_code(): void
    {
        $this->assertSame('AbCdEfGhIjKlMnOpQrStUvWxYz012345', LoyaltyWalletPassService::publicIdFromBarcode(' PICHAFID:AbCdEfGhIjKlMnOpQrStUvWxYz012345 '));
        $this->assertNull(LoyaltyWalletPassService::publicIdFromBarcode('PICHAFID:../../etc'));
    }

    public function test_keeps_ten_locations_at_most(): void
    {
        $locations = array_fill(0, 12, ['latitude' => -12.78, 'longitude' => 45.22, 'relevant_text' => '']);

        $this->assertCount(10, $this->service->locations($this->program()->setLocations($locations)));
    }

    private function program(): LoyaltyProgramDomainObject
    {
        return (new LoyaltyProgramDomainObject)
            ->setId(3)
            ->setOrganizerId(24)
            ->setName('Carte fidélité Barge')
            ->setRewardDescription('1 menu offert')
            ->setStampsRequired(9)
            ->setBackgroundColor('#0E3B43')
            ->setForegroundColor('#FFFFFF')
            ->setLabelColor('#D1D5DB')
            ->setLocations([
                ['latitude' => -12.7806, 'longitude' => 45.2279, 'relevant_text' => 'Un café vous attend à la Barge'],
                ['latitude' => -12.781, 'longitude' => 45.228, 'relevant_text' => null],
            ])
            ->setMaxDistance(150)
            ->setBeaconUuid('f7826da6-4fa2-4e98-8024-bc5b71e0893e')
            ->setBeaconMajor(1)
            ->setBeaconMinor(2)
            ->setBeaconRelevantText('Montrez votre carte au comptoir');
    }

    private function card(): LoyaltyCardDomainObject
    {
        return (new LoyaltyCardDomainObject)
            ->setId(5)
            ->setPublicId('AbCdEfGhIjKlMnOpQrStUvWxYz012345')
            ->setFirstName('Awa')
            ->setStamps(4)
            ->setRewardsEarned(1);
    }
}
