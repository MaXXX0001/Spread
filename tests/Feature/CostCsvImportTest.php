<?php

namespace Tests\Feature;

use App\Filament\Resources\Costs\Pages\ListCosts;
use App\Models\Campaign;
use App\Models\Cost;
use App\Models\CpaNetwork;
use App\Models\ExchangeRate;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Models\User;
use App\Money\CostCsvImporter;
use App\Money\CostImportResult;
use App\Money\CostRules;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class CostCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private const string HEADER = 'date,campaign,zone,amount,currency';

    private Campaign $first;

    private Campaign $second;

    protected function setUp(): void
    {
        parent::setUp();

        $source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => [['param' => 'zone', 'macro' => '{zoneid}']],
        ]);
        $network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);
        $offer = Offer::create([
            'name' => 'Nutra UA',
            'cpa_network_id' => $network->id,
            'url_template' => 'https://offer.example/lp?sub1={click_id}',
        ]);
        $this->first = Campaign::create([
            'name' => 'PH / Nutra UA',
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);
        $this->second = Campaign::create([
            'name' => 'PH / Nutra PL',
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);
    }

    public function test_valid_file_is_imported(): void
    {
        $result = $this->import([
            "2026-10-10,{$this->first->alias},8841,10.5,USD",
            "2026-10-10,{$this->first->alias},9120,4,usd",
            "2026-10-10,{$this->second->alias},,1000,UAH",
        ]);

        $this->assertFalse($result->failed());
        $this->assertSame(3, $result->created);
        $this->assertSame(0, $result->updated);
        $wholeCampaign = Cost::query()->where('campaign_id', '=', $this->second->id)->sole();
        $this->assertNull($wholeCampaign->zone);
        $this->assertSame('1000.0000', $wholeCampaign->amount);
        $this->assertSame('UAH', $wholeCampaign->currency);
        $this->assertSame(2, Cost::query()->where('currency', '=', 'USD')->count());
    }

    public function test_reimport_replaces_amounts(): void
    {
        $rows = [
            "2026-10-10,{$this->first->alias},8841,10.5,USD",
            "2026-10-10,{$this->first->alias},9120,4,USD",
            "2026-10-11,{$this->second->alias},,1000,UAH",
        ];
        $this->import($rows);
        $rows[1] = "2026-10-10,{$this->first->alias},9120,7.25,EUR";

        $result = $this->import($rows);

        $changed = Cost::query()->where('zone', '=', '9120')->sole();
        $this->assertSame(0, $result->created);
        $this->assertSame(3, $result->updated);
        $this->assertSame(3, Cost::query()->count());
        $this->assertSame('7.2500', $changed->amount);
        $this->assertSame('EUR', $changed->currency);
    }

    public function test_one_bad_line_imports_nothing(): void
    {
        $rows = [];

        for ($i = 1; $i <= 10; $i++) {
            $alias = $i === 3 ? 'nosuchalias' : $this->first->alias;
            $day = sprintf('%02d', $i);
            $rows[] = "2026-10-{$day},{$alias},z{$i},1,USD";
        }

        $result = $this->import($rows);

        // The header is line 1, so the third data row is line 4.
        $this->assertTrue($result->failed());
        $this->assertSame(['Line 4: unknown campaign alias "nosuchalias".'], $result->errors);
        $this->assertSame(0, Cost::query()->count());
    }

    public function test_missing_columns_are_reported(): void
    {
        $result = $this->import(["2026-10-10,{$this->first->alias},,USD"], header: 'date,campaign,zone,currency');

        $this->assertSame(['Missing columns: amount.'], $result->errors);
    }

    public function test_invalid_fields_are_reported_with_line_numbers(): void
    {
        $result = $this->import([
            "2026-13-01,{$this->first->alias},,1,USD",
            "2026-10-10,{$this->first->alias},a,\"12,5\",USD",
            "2026-10-10,{$this->first->alias},b,1,US",
        ]);

        $this->assertCount(3, $result->errors);
        $this->assertStringStartsWith('Line 2: invalid date "2026-13-01"', $result->errors[0]);
        $this->assertStringStartsWith('Line 3: invalid amount "12,5"', $result->errors[1]);
        $this->assertStringStartsWith('Line 4: invalid currency "US"', $result->errors[2]);
        $this->assertSame(0, Cost::query()->count());
    }

    public function test_duplicate_key_in_file_is_reported(): void
    {
        $result = $this->import([
            "2026-10-10,{$this->first->alias},8841,1,USD",
            "2026-10-10,{$this->first->alias},8841,2,USD",
        ]);

        $this->assertSame(['Line 3: the same campaign, date and zone as line 2.'], $result->errors);
    }

    public function test_campaign_and_zones_in_one_file_conflict(): void
    {
        $result = $this->import([
            "2026-10-10,{$this->first->alias},8841,1,USD",
            "2026-10-10,{$this->first->alias},,2,USD",
        ]);

        $this->assertCount(1, $result->errors);
        $this->assertStringStartsWith('Line 3: the file mixes a whole-campaign cost and zone costs', $result->errors[0]);
        $this->assertSame(0, Cost::query()->count());
    }

    public function test_conflict_with_stored_cost_is_reported(): void
    {
        Cost::create([
            'campaign_id' => $this->first->id,
            'date' => '2026-10-10',
            'zone' => null,
            'amount' => '5',
            'currency' => 'USD',
        ]);

        $result = $this->import(["2026-10-10,{$this->first->alias},8841,1,USD"]);

        $this->assertSame(['Line 2: '.CostRules::CAMPAIGN_COST_EXISTS], $result->errors);
        $this->assertSame(1, Cost::query()->count());
    }

    public function test_bom_any_column_order_and_blank_lines_are_accepted(): void
    {
        $csv = "\u{FEFF}currency,amount,zone,campaign,date\n"
            ."USD,3,,{$this->first->alias},2026-10-10\n"
            ."\n"
            ."EUR,4,,{$this->second->alias},2026-10-10\n";

        $result = $this->importCsv($csv);

        $this->assertFalse($result->failed());
        $this->assertSame(2, $result->created);
    }

    public function test_line_numbers_count_blank_lines(): void
    {
        $csv = self::HEADER."\n\n2026-10-10,nosuchalias,,1,USD\n";

        $result = $this->importCsv($csv);

        $this->assertSame(['Line 3: unknown campaign alias "nosuchalias".'], $result->errors);
    }

    public function test_imported_costs_get_base_amount(): void
    {
        foreach (['USD' => '44.8526', 'EUR' => '50.1385'] as $currency => $rate) {
            ExchangeRate::create(['date' => '2026-10-10', 'currency' => $currency, 'uah_per_unit' => $rate, 'source' => ExchangeRate::SOURCE_NBU]);
        }

        $this->import(["2026-10-10,{$this->first->alias},,100,EUR"]);

        $this->assertSame('111.7850', Cost::query()->sole()->amount_base);
    }

    public function test_admin_action_imports_file_and_shows_summary(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        $content = self::HEADER."\n2026-10-10,{$this->first->alias},,10,USD\n2026-10-10,{$this->second->alias},,20,USD\n";
        $file = UploadedFile::fake()->createWithContent('costs.csv', $content);

        Livewire::test(ListCosts::class)
            ->callAction('importCsv', data: ['file' => $file])
            ->assertHasNoActionErrors()
            ->assertNotified('2 created, 0 updated');

        $this->assertSame(2, Cost::query()->count());
    }

    public function test_admin_action_shows_errors(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        $file = UploadedFile::fake()->createWithContent('costs.csv', self::HEADER."\n2026-10-10,nosuchalias,,10,USD\n");

        Livewire::test(ListCosts::class)
            ->callAction('importCsv', data: ['file' => $file])
            ->assertNotified('Nothing imported');

        $this->assertSame(0, Cost::query()->count());
    }

    /**
     * @param  list<string>  $rows
     */
    private function import(array $rows, string $header = self::HEADER): CostImportResult
    {
        $csv = $header."\n".implode("\n", $rows)."\n";

        return $this->importCsv($csv);
    }

    private function importCsv(string $csv): CostImportResult
    {
        $path = tempnam(sys_get_temp_dir(), 'costs');
        file_put_contents($path, $csv);
        $importer = new CostCsvImporter;

        try {
            return $importer->import($path);
        } finally {
            unlink($path);
        }
    }
}
