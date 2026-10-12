<?php

namespace Tests\Feature;

use App\Filament\Resources\ExchangeRates\Pages\CreateExchangeRate;
use App\Filament\Resources\ExchangeRates\Pages\EditExchangeRate;
use App\Filament\Resources\ExchangeRates\Pages\ListExchangeRates;
use App\Models\ExchangeRate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExchangeRateResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->actingAs($user);

        Filament::setCurrentPanel('admin');
    }

    public function test_manual_rate_is_added(): void
    {
        Livewire::test(CreateExchangeRate::class)
            ->fillForm([
                'date' => '2026-10-10',
                'currency' => 'pln',
                'uah_per_unit' => '11.25',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rate = ExchangeRate::query()->sole();

        $this->assertSame('PLN', $rate->currency);
        $this->assertSame('2026-10-10', $rate->date->toDateString());
        $this->assertSame('11.25000000', $rate->uah_per_unit);
        $this->assertSame(ExchangeRate::SOURCE_MANUAL, $rate->source);
    }

    public function test_edited_nbu_rate_becomes_manual(): void
    {
        $rate = $this->createRate('USD', '2026-10-10', '44.8526', ExchangeRate::SOURCE_NBU);

        Livewire::test(EditExchangeRate::class, ['record' => $rate->getRouteKey()])
            ->fillForm([
                'uah_per_unit' => '45',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $rate->refresh();

        $this->assertSame('45.00000000', $rate->uah_per_unit);
        $this->assertSame(ExchangeRate::SOURCE_MANUAL, $rate->source);
    }

    public function test_rate_is_saved_with_unchanged_currency_and_date(): void
    {
        $rate = $this->createRate('USD', '2026-10-10', '44.8526', ExchangeRate::SOURCE_NBU);

        Livewire::test(EditExchangeRate::class, ['record' => $rate->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidDataProvider(): array
    {
        return [
            'zero rate' => [['uah_per_unit' => '0'], 'uah_per_unit'],
            'negative rate' => [['uah_per_unit' => '-1'], 'uah_per_unit'],
            'too many decimals' => [['uah_per_unit' => '1.123456789'], 'uah_per_unit'],
            'hryvnia' => [['currency' => 'uah'], 'currency'],
            'currency too long' => [['currency' => 'EURO'], 'currency'],
            'duplicate currency and date' => [['currency' => 'usd'], 'currency'],
            'no date' => [['date' => null], 'date'],
        ];
    }

    /**
     * @param  array<string, string|null>  $data
     */
    #[DataProvider('invalidDataProvider')]
    public function test_invalid_rate_is_rejected(array $data, string $field): void
    {
        $this->createRate('USD', '2026-10-10', '44.8526', ExchangeRate::SOURCE_NBU);

        Livewire::test(CreateExchangeRate::class)
            ->fillForm($data + [
                'date' => '2026-10-10',
                'currency' => 'PLN',
                'uah_per_unit' => '11.25',
            ])
            ->call('create')
            ->assertHasFormErrors([$field]);

        $this->assertSame(1, ExchangeRate::query()->count());
    }

    public function test_same_currency_on_another_date_is_allowed(): void
    {
        $this->createRate('USD', '2026-10-10', '44.8526', ExchangeRate::SOURCE_NBU);

        Livewire::test(CreateExchangeRate::class)
            ->fillForm([
                'date' => '2026-10-11',
                'currency' => 'USD',
                'uah_per_unit' => '44.9',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, ExchangeRate::query()->count());
    }

    public function test_list_is_sorted_and_filtered(): void
    {
        $older = $this->createRate('USD', '2026-10-09', '44.8', ExchangeRate::SOURCE_NBU);
        $newer = $this->createRate('USD', '2026-10-10', '44.85', ExchangeRate::SOURCE_NBU);
        $euro = $this->createRate('EUR', '2026-10-10', '50.1', ExchangeRate::SOURCE_MANUAL);

        Livewire::test(ListExchangeRates::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
            ->filterTable('currency', 'EUR')
            ->assertCanSeeTableRecords([$euro])
            ->assertCanNotSeeTableRecords([$newer, $older]);

        Livewire::test(ListExchangeRates::class)
            ->filterTable('period', ['from' => '2026-10-10', 'until' => '2026-10-10'])
            ->assertCanSeeTableRecords([$newer, $euro])
            ->assertCanNotSeeTableRecords([$older]);
    }

    public function test_rates_cannot_be_deleted(): void
    {
        $rate = $this->createRate('USD', '2026-10-10', '44.8526', ExchangeRate::SOURCE_NBU);

        $component = Livewire::test(ListExchangeRates::class)
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');

        $table = $component->instance()->getTable();

        $this->assertSame([], $table->getFlatBulkActions());

        Livewire::test(EditExchangeRate::class, ['record' => $rate->getRouteKey()])
            ->assertActionDoesNotExist('delete');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        Auth::logout();

        $response = $this->get('/admin/exchange-rates');

        $response->assertRedirect('/admin/login');
    }

    private function createRate(string $currency, string $date, string $rate, string $source): ExchangeRate
    {
        return ExchangeRate::create([
            'date' => $date,
            'currency' => $currency,
            'uah_per_unit' => $rate,
            'source' => $source,
        ]);
    }
}
