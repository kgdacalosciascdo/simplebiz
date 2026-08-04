<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_partner_is_shared_across_roles_and_has_history(): void
    {
        [$user, $company] = $this->ownerContext();
        $response = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id)->withHeader('Idempotency-Key', 'bp-create')->postJson('/api/v1/master-registries/business-partners', ['code' => 'BP-001', 'official_name' => 'Acme Supply', 'roles' => ['customer', 'supplier'], 'primary_email' => 'hello@acme.test']);
        $response->assertCreated()->assertJsonPath('data.code', 'BP-001')->assertJsonPath('data.roles.0.role', 'customer');
        $id = $response->json('data.id');
        $this->assertDatabaseHas('business_partner_roles', ['business_partner_id' => $id, 'role' => 'supplier', 'status' => 'active']);
        $this->getJson("/api/v1/master-registries/business-partners/{$id}/history")->assertOk()->assertJsonPath('data.0.action', 'business_partner.created');
    }

    public function test_probable_duplicate_requires_explicit_override(): void
    {
        [$user, $company] = $this->ownerContext();
        $headers = ['X-Company-ID' => $company->id];
        $this->actingAs($user, 'sanctum')->withHeaders($headers)->postJson('/api/v1/master-registries/business-partners', ['code' => 'BP-001', 'official_name' => 'Acme Supply'])->assertCreated();
        $this->actingAs($user, 'sanctum')->withHeaders($headers)->postJson('/api/v1/master-registries/business-partners', ['code' => 'BP-002', 'official_name' => 'Acme Supply'])->assertStatus(409)->assertJsonPath('errors.duplicates.0.code', 'BP-001');
        $this->actingAs($user, 'sanctum')->withHeaders($headers)->postJson('/api/v1/master-registries/business-partners', ['code' => 'BP-002', 'official_name' => 'Acme Supply', 'duplicate_override' => true, 'duplicate_override_reason' => 'Confirmed separate legal entity.'])->assertCreated();
    }

    public function test_service_cannot_be_stock_managed_and_stock_product_needs_unit(): void
    {
        [$user, $company] = $this->ownerContext();
        $headers = ['X-Company-ID' => $company->id];
        $this->actingAs($user, 'sanctum')->withHeaders($headers)->postJson('/api/v1/master-registries/products-services', ['code' => 'SVC-001', 'name' => 'Consulting', 'record_type' => 'service', 'stock_managed' => true])->assertStatus(409);
        $this->actingAs($user, 'sanctum')->withHeaders($headers)->postJson('/api/v1/master-registries/products-services', ['code' => 'PRD-001', 'name' => 'Widget', 'record_type' => 'product', 'stock_managed' => true])->assertStatus(409);
    }

    public function test_category_and_unit_dependencies_block_deactivation(): void
    {
        [$user, $company] = $this->ownerContext();
        $headers = ['X-Company-ID' => $company->id];
        $client = $this->actingAs($user, 'sanctum')->withHeaders($headers);
        $category = $client->postJson('/api/v1/master-registries/categories', ['code' => 'GOODS', 'name' => 'Goods'])->json('data.id');
        $unit = $client->postJson('/api/v1/master-registries/units', ['code' => 'EA', 'name' => 'Each'])->json('data.id');
        $client->postJson('/api/v1/master-registries/products-services', ['code' => 'PRD-001', 'name' => 'Widget', 'record_type' => 'product', 'stock_managed' => true, 'category_id' => $category, 'base_unit_id' => $unit])->assertCreated();
        $client->postJson("/api/v1/master-registries/categories/{$category}/deactivate", ['reason' => 'No longer needed'])->assertStatus(409);
        $client->postJson("/api/v1/master-registries/units/{$unit}/deactivate", ['reason' => 'No longer needed'])->assertStatus(409);
    }

    public function test_registry_records_are_company_scoped(): void
    {
        [$user, $company] = $this->ownerContext();
        $other = Company::create(['name' => 'Other Co', 'slug' => 'other-co', 'status' => 'active', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en', 'setup_status' => 'completed']);
        $user->companies()->attach($other->id, ['status' => 'active', 'is_owner' => true]);
        $record = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/master-registries/business-partners', ['code' => 'BP-001', 'official_name' => 'Company One'])->json('data.id');
        $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $other->id)->getJson("/api/v1/master-registries/business-partners/{$record}")->assertNotFound();
    }

    private function ownerContext(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Acme Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $user = User::findOrFail($setup->json('data.user.id'));
        $company = $user->companies()->firstOrFail();

        return [$user, $company];
    }
}
