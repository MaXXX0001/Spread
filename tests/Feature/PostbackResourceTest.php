<?php

namespace Tests\Feature;

use App\Filament\Resources\Conversions\Pages\ViewConversion;
use App\Filament\Resources\Postbacks\Pages\ListPostbacks;
use App\Filament\Resources\Postbacks\Pages\ViewPostback;
use App\Models\Conversion;
use App\Models\CpaNetwork;
use App\Models\Postback;
use App\Models\User;
use App\Postbacks\PostbackProcessor;
use App\Postbacks\PostbackState;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PostbackResourceTest extends TestCase
{
    use RefreshDatabase;

    private const string CLICK_ID = '01J8Z3K4M5N6P7Q8R9S0T1V2W3';

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

    public function test_rejected_postback_with_unknown_key_has_no_network(): void
    {
        $postback = $this->createPostback(PostbackState::REJECTED, reason: 'invalid_key', withNetwork: false);

        Livewire::test(ListPostbacks::class)
            ->assertCanSeeTableRecords([$postback])
            ->assertTableColumnStateSet('state', PostbackState::REJECTED, record: $postback)
            ->assertTableColumnStateSet('reason', 'invalid_key', record: $postback)
            ->assertTableColumnStateSet('cpaNetwork.name', null, record: $postback);
    }

    public function test_click_id_and_status_come_from_params(): void
    {
        $postback = $this->createPostback(PostbackState::PROCESSED, params: ['click_id' => self::CLICK_ID, 'status' => 'sale']);

        Livewire::test(ListPostbacks::class)
            ->assertTableColumnStateSet('param_click_id', self::CLICK_ID, record: $postback)
            ->assertTableColumnStateSet('param_status', 'sale', record: $postback)
            ->assertTableColumnStateSet('cpaNetwork.name', 'AdCombo', record: $postback);
    }

    public function test_log_is_filtered_by_result_and_network(): void
    {
        $failed = $this->createPostback(PostbackState::FAILED, reason: 'LogicException: bug');
        $processed = $this->createPostback(PostbackState::PROCESSED);
        $unknownKey = $this->createPostback(PostbackState::REJECTED, reason: 'invalid_key', withNetwork: false);

        Livewire::test(ListPostbacks::class)
            ->filterTable('state', 'failed')
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$processed, $unknownKey]);

        Livewire::test(ListPostbacks::class)
            ->filterTable('cpaNetwork', $this->network->id)
            ->assertCanSeeTableRecords([$failed, $processed])
            ->assertCanNotSeeTableRecords([$unknownKey]);
    }

    public function test_newest_postback_is_listed_first(): void
    {
        $older = $this->createPostback(PostbackState::PROCESSED, receivedAt: '2026-10-12 10:00:00');
        $newer = $this->createPostback(PostbackState::PROCESSED, receivedAt: '2026-10-12 11:00:00');

        Livewire::test(ListPostbacks::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }

    public function test_view_page_shows_raw_params_ip_reason_and_conversion_link(): void
    {
        $postback = $this->createPostback(PostbackState::RECEIVED, params: ['click_id' => self::CLICK_ID, 'status' => 'approved', 'sub' => '{status}']);
        $processor = new PostbackProcessor;
        $processor->process($postback);
        $conversion = Conversion::query()->sole();

        Livewire::test(ViewPostback::class, ['record' => $postback->getRouteKey()])
            ->assertOk()
            ->assertSee('{status}')
            ->assertSee('198.51.100.7')
            ->assertSee('processed')
            ->assertSee('/admin/conversions/'.$conversion->id);
    }

    public function test_view_page_shows_error_text(): void
    {
        $postback = $this->createPostback(PostbackState::FAILED, reason: 'LogicException: Processing bug');

        Livewire::test(ViewPostback::class, ['record' => $postback->getRouteKey()])
            ->assertSee('LogicException: Processing bug')
            ->assertSee('failed');
    }

    public function test_conversion_history_links_to_postbacks(): void
    {
        $postback = $this->createPostback(PostbackState::RECEIVED, params: ['click_id' => self::CLICK_ID, 'status' => 'hold']);
        $processor = new PostbackProcessor;
        $processor->process($postback);
        $conversion = Conversion::query()->sole();

        Livewire::test(ViewConversion::class, ['record' => $conversion->getRouteKey()])
            ->assertSee('/admin/postbacks/'.$postback->postback_id);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        Auth::logout();

        $response = $this->get('/admin/postbacks');

        $response->assertRedirect('/admin/login');
    }

    public function test_create_page_does_not_exist(): void
    {
        $response = $this->get('/admin/postbacks/create');

        $response->assertNotFound();
    }

    public function test_only_index_and_view_routes_exist(): void
    {
        $this->assertTrue(Route::has('filament.admin.resources.postbacks.index'));
        $this->assertTrue(Route::has('filament.admin.resources.postbacks.view'));
        $this->assertFalse(Route::has('filament.admin.resources.postbacks.create'));
        $this->assertFalse(Route::has('filament.admin.resources.postbacks.edit'));
    }

    public function test_pages_have_no_create_edit_or_delete_actions(): void
    {
        $postback = $this->createPostback(PostbackState::PROCESSED);

        $component = Livewire::test(ListPostbacks::class)
            ->assertActionDoesNotExist('create')
            ->assertTableActionExists('view', record: $postback)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');

        $table = $component->instance()->getTable();

        $this->assertSame([], $table->getToolbarActions());
        $this->assertSame([], $table->getFlatBulkActions());

        Livewire::test(ViewPostback::class, ['record' => $postback->getRouteKey()])
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete');
    }

    /**
     * @param  array<string, string>  $params
     */
    private function createPostback(
        PostbackState $state,
        ?string $reason = null,
        bool $withNetwork = true,
        array $params = ['click_id' => self::CLICK_ID, 'status' => 'approved'],
        string $receivedAt = '2026-10-12 10:00:00',
    ): Postback {
        return Postback::forceCreate([
            'postback_id' => (string) Str::ulid(),
            'received_at' => Carbon::parse($receivedAt, 'UTC'),
            'cpa_network_id' => $withNetwork ? $this->network->id : null,
            'ip' => '198.51.100.7',
            'params' => $params,
            'state' => $state,
            'reason' => $reason,
        ]);
    }
}
