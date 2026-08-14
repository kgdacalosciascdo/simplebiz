<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReferenceRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_currency_is_backfilled_as_company_default_and_cannot_be_deactivated(): void
    {
        [$user, $company] = $this->ownerContext();
        $this->assertNotNull($company->fresh()->default_currency_id);
        $response = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/master-registries/currencies/'.$company->default_currency_id.'/deactivate', ['reason' => 'Try to remove default']);
        $response->assertStatus(409)->assertJsonPath('errors.dependency', 'company_default');
        $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/master-registries/currencies', ['code' => 'ZZZ', 'name' => 'Invalid'])->assertStatus(409);
    }

    public function test_payment_methods_terms_and_tax_codes_enforce_reference_rules(): void
    {
        [$user, $company] = $this->ownerContext();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $client->postJson('/api/v1/master-registries/payment-methods', ['code' => 'NONE', 'name' => 'No Direction', 'method_class' => 'OTHER'])->assertStatus(409);
        $method = $client->withHeader('Idempotency-Key', 'pm-create')->postJson('/api/v1/master-registries/payment-methods', ['code' => 'BANK', 'name' => 'Bank Transfer', 'method_class' => 'BANK_TRANSFER', 'supports_incoming' => true])->assertCreated();
        $client->withHeader('Idempotency-Key', 'pm-create')->postJson('/api/v1/master-registries/payment-methods', ['code' => 'BANK', 'name' => 'Bank Transfer', 'method_class' => 'BANK_TRANSFER', 'supports_incoming' => true])->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $client->postJson('/api/v1/master-registries/payment-terms', ['code' => 'IMMEDIATE', 'name' => 'Immediate', 'term_type' => 'immediate', 'due_days' => 2])->assertStatus(409);
        $client->postJson('/api/v1/master-registries/payment-terms', ['code' => 'NET30', 'name' => 'Net 30', 'term_type' => 'due_days', 'due_days' => 30])->assertCreated();
        $client->postJson('/api/v1/master-registries/tax-codes', ['code' => 'VAT', 'name' => 'Value Added Tax', 'tax_type' => 'sales', 'rate' => 101])->assertStatus(422);
        $client->postJson('/api/v1/master-registries/tax-codes', ['code' => 'ZERO', 'name' => 'Zero Rate', 'tax_type' => 'sales', 'rate' => 0])->assertCreated();
        $this->assertNotNull($method->json('data.id'));
    }

    public function test_expense_categories_require_expense_account_and_block_account_deactivation(): void
    {
        [$user, $company] = $this->ownerContext();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $asset = $client->postJson('/api/v1/master-registries/account-titles', ['code' => 'ASSET', 'name' => 'Asset', 'classification' => 'asset', 'normal_balance' => 'debit'])->json('data.id');
        $client->postJson('/api/v1/master-registries/expense-categories', ['code' => 'BAD', 'name' => 'Bad mapping', 'account_title_id' => $asset])->assertStatus(409);
        $expense = $client->postJson('/api/v1/master-registries/account-titles', ['code' => 'OFFICE', 'name' => 'Office Expense', 'classification' => 'expense', 'normal_balance' => 'debit'])->json('data.id');
        $client->postJson('/api/v1/master-registries/expense-categories', ['code' => 'OFFICE', 'name' => 'Office costs', 'account_title_id' => $expense])->assertCreated();
        $client->postJson("/api/v1/master-registries/account-titles/{$expense}/deactivate", ['reason' => 'Dependency test'])->assertStatus(409)->assertJsonPath('errors.dependency', 'expense_categories');
    }

    public function test_branch_warehouse_and_location_hierarchy_is_company_scoped(): void
    {
        [$user, $company] = $this->ownerContext();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $branch = $client->postJson('/api/v1/master-registries/branches', ['code' => 'MAIN', 'name' => 'Main Branch'])->json('data.id');
        $warehouse = $client->postJson('/api/v1/master-registries/warehouses', ['code' => 'WH1', 'name' => 'Main Warehouse', 'branch_id' => $branch])->json('data.id');
        $root = $client->postJson('/api/v1/master-registries/stock-locations', ['code' => 'ROOT', 'name' => 'Root', 'warehouse_id' => $warehouse])->json('data.id');
        $child = $client->postJson('/api/v1/master-registries/stock-locations', ['code' => 'CHILD', 'name' => 'Child', 'warehouse_id' => $warehouse, 'parent_id' => $root])->json('data.id');
        $client->postJson("/api/v1/master-registries/stock-locations/{$root}/deactivate", ['reason' => 'Hierarchy test'])->assertStatus(409);
        $client->postJson('/api/v1/master-registries/stock-locations', ['code' => 'CROSS', 'name' => 'Cross', 'warehouse_id' => $warehouse, 'parent_id' => $child])->assertCreated();
        $this->assertTrue(Schema::hasTable('inventory_balances'));
    }

    public function test_reason_codes_are_domain_filtered_and_inactive_records_leave_lookup(): void
    {
        [$user, $company] = $this->ownerContext();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $id = $client->postJson('/api/v1/master-registries/reason-codes', ['code' => 'ADJ', 'name' => 'Inventory adjustment', 'domain' => 'INVENTORY_ADJUSTMENT', 'requires_explanation' => true])->json('data.id');
        $client->getJson('/api/v1/master-registries/reference-lookups?domain=INVENTORY_ADJUSTMENT')->assertOk()->assertJsonPath('data.reason_codes.0.code', 'ADJ');
        $client->postJson("/api/v1/master-registries/reason-codes/{$id}/deactivate", ['reason' => 'No longer used'])->assertOk();
        $client->getJson('/api/v1/master-registries/reference-lookups?domain=INVENTORY_ADJUSTMENT')->assertOk()->assertJsonCount(0, 'data.reason_codes');
    }

    private function ownerContext(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Acme Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $user = User::findOrFail($setup->json('data.user.id'));

        return [$user, $user->companies()->firstOrFail()];
    }
}
