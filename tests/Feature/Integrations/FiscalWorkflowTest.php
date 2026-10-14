<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Fiscal\FiscalIntegrationException;
use App\Integrations\Fiscal\FiscalProvider;
use App\Integrations\Fiscal\FiscalRequest;
use App\Integrations\Fiscal\FiscalResult;
use App\Integrations\Fiscal\FiscalWorkflow;
use App\Integrations\Fiscal\UnconfiguredFiscalProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FiscalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function sale(): int
    {
        $user = User::factory()->create();
        $channel = DB::table('sales_channels')->value('id');

        return DB::table('sales')->insertGetId(['reference' => 'FISCAL-TEST', 'channel_id' => $channel, 'recognition_policy' => 'dispatch', 'fiscal_required' => true, 'ordered_on' => '2026-10-01', 'net_total' => '10.00', 'tax_total' => '0.00', 'gross_total' => '10.00', 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_unconfigured_provider_cannot_simulate_issuance(): void
    {
        $flow = new FiscalWorkflow(new UnconfiguredFiscalProvider);
        $id = $flow->prepare($this->sale(), ['amount' => '10.00'], 'fiscal-unconfigured');
        try {
            $flow->submit($id);
            $this->fail('Unconfigured issuance was accepted.');
        } catch (FiscalIntegrationException) {
        }
        $this->assertSame('pending', DB::table('fiscal_documents')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('fiscal_attempts')->count());
    }

    public function test_timeout_blocks_resend_and_lookup_can_confirm_without_second_submission(): void
    {
        $provider = new class implements FiscalProvider
        {
            public int $calls = 0;

            public function name(): string
            {
                return 'test-only';
            }

            public function assertReady(): void {}

            public function submit(FiscalRequest $request): FiscalResult
            {
                $this->calls++;
                throw new \RuntimeException('test timeout after external acceptance');
            }

            public function lookup(FiscalRequest $request): FiscalResult
            {
                return new FiscalResult('confirmed', 'TEST-ONLY-REFERENCE', ['test' => true]);
            }
        };
        $flow = new FiscalWorkflow($provider);
        $id = $flow->prepare($this->sale(), ['amount' => '10.00'], 'fiscal-timeout');
        $flow->submit($id);
        $this->assertSame('outcome_unknown', DB::table('fiscal_documents')->where('id', $id)->value('status'));
        try {
            $flow->submit($id);
            $this->fail('Blind resend was accepted.');
        } catch (FiscalIntegrationException) {
        }
        $this->assertSame(1, $provider->calls);
        $flow->reconcile($id);
        $this->assertSame('confirmed', DB::table('fiscal_documents')->where('id', $id)->value('status'));
        $this->assertSame(1, $provider->calls);
    }

    public function test_operation_key_cannot_change_immutable_payload(): void
    {
        $flow = new FiscalWorkflow(new UnconfiguredFiscalProvider);
        $sale = $this->sale();
        $flow->prepare($sale, ['amount' => '10.00'], 'fiscal-stable');
        $this->expectException(FiscalIntegrationException::class);
        $flow->prepare($sale,['amount' => '20.00'],'fiscal-stable');
    }
}
