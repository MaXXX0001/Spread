<?php

namespace Tests\Feature;

use App\Filament\Resources\Offers\Pages\CreateOffer;
use App\Filament\Resources\Offers\Pages\EditOffer;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OfferResourceTest extends TestCase
{
    use RefreshDatabase;

    private const URL_TEMPLATE = 'https://offer.example/lp?sub1={click_id}&sub2={zone}';

    private CpaNetwork $network;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->actingAs($user);

        Filament::setCurrentPanel('admin');

        $this->network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);
    }

    public function test_offer_is_created_and_listed_with_network_name(): void
    {
        Livewire::test(CreateOffer::class)
            ->fillForm([
                'name' => 'Nutra UA',
                'cpa_network_id' => $this->network->id,
                'url_template' => self::URL_TEMPLATE,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $offer = Offer::query()->sole();

        $this->assertSame('Nutra UA', $offer->name);
        $this->assertSame($this->network->id, $offer->cpa_network_id);

        Livewire::test(ListOffers::class)
            ->assertCanSeeTableRecords([$offer])
            ->assertSee('Nutra UA')
            ->assertSee('AdCombo');
    }

    public function test_url_template_is_stored_as_is(): void
    {
        Livewire::test(CreateOffer::class)
            ->fillForm([
                'name' => 'Nutra UA',
                'cpa_network_id' => $this->network->id,
                'url_template' => self::URL_TEMPLATE,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $storedTemplate = DB::table('offers')->value('url_template');

        $this->assertSame(self::URL_TEMPLATE, $storedTemplate);

        $offer = Offer::query()->sole();

        Livewire::test(EditOffer::class, ['record' => $offer->getRouteKey()])
            ->assertSchemaStateSet([
                'url_template' => self::URL_TEMPLATE,
            ]);
    }

    public function test_template_without_click_id_is_rejected_with_message(): void
    {
        $component = Livewire::test(CreateOffer::class)
            ->fillForm([
                'name' => 'Nutra UA',
                'cpa_network_id' => $this->network->id,
                'url_template' => 'https://offer.example/lp?sub2={zone}',
            ])
            ->call('create')
            ->assertHasFormErrors(['url_template' => 'regex']);

        $message = $component->errors()->first('data.url_template');

        $this->assertStringContainsString('{click_id}', $message);
        $this->assertDatabaseCount('offers', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidDataProvider(): array
    {
        return [
            'no network' => [['cpa_network_id' => null], 'cpa_network_id'],
            'empty name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name'],
            'empty template' => [['url_template' => ''], 'url_template'],
            'template without scheme' => [['url_template' => 'offer.example/lp?sub1={click_id}'], 'url_template'],
            'template with other scheme' => [['url_template' => 'ftp://offer.example/lp?sub1={click_id}'], 'url_template'],
            'template too long' => [['url_template' => 'https://offer.example/lp?sub1={click_id}&x='.str_repeat('a', 2006)], 'url_template'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidDataProvider')]
    public function test_invalid_data_is_rejected(array $data, string $errorKey): void
    {
        $formData = array_merge([
            'name' => 'Nutra UA',
            'cpa_network_id' => $this->network->id,
            'url_template' => self::URL_TEMPLATE,
        ], $data);

        Livewire::test(CreateOffer::class)
            ->fillForm($formData)
            ->call('create')
            ->assertHasFormErrors([$errorKey]);

        $this->assertDatabaseCount('offers', 0);
    }

    public function test_template_of_max_length_is_accepted(): void
    {
        $template = 'https://offer.example/lp?sub1={click_id}&x='.str_repeat('a', 2005);

        $this->assertSame(2048, strlen($template));

        Livewire::test(CreateOffer::class)
            ->fillForm([
                'name' => 'Nutra UA',
                'cpa_network_id' => $this->network->id,
                'url_template' => $template,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('offers', 1);
    }

    public function test_offer_is_edited(): void
    {
        $offer = Offer::create([
            'name' => 'Nutra UA',
            'cpa_network_id' => $this->network->id,
            'url_template' => self::URL_TEMPLATE,
        ]);

        $otherNetwork = CpaNetwork::create([
            'name' => 'Dr.Cash',
        ]);

        Livewire::test(EditOffer::class, ['record' => $offer->getRouteKey()])
            ->assertSchemaStateSet([
                'name' => 'Nutra UA',
                'cpa_network_id' => $this->network->id,
                'url_template' => self::URL_TEMPLATE,
            ])
            ->fillForm([
                'name' => 'Nutra PL',
                'cpa_network_id' => $otherNetwork->id,
                'url_template' => 'http://offer.example/pl?click={click_id}',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $offer->refresh();

        $this->assertSame('Nutra PL', $offer->name);
        $this->assertSame($otherNetwork->id, $offer->cpa_network_id);
        $this->assertSame('http://offer.example/pl?click={click_id}', $offer->url_template);
    }

    public function test_offer_is_deleted(): void
    {
        $offer = Offer::create([
            'name' => 'Nutra UA',
            'cpa_network_id' => $this->network->id,
            'url_template' => self::URL_TEMPLATE,
        ]);

        Livewire::test(EditOffer::class, ['record' => $offer->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($offer);
    }
}
