<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\CompanyOnboarding;
use App\Services\Subscriptions\OfferCatalog;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OfferPublicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['identity.billing.offers' => []]);
        Schema::create('identity_billing_offer_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('offer_key');
            $table->unsignedInteger('version');
            $table->json('terms');
            $table->string('status');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at');
            $table->unique(['offer_key', 'version']);
        });
    }

    public function test_editing_a_published_offer_preserves_its_live_price_until_publication(): void
    {
        $catalog = app(OfferCatalog::class);
        $original = ['kind' => 'PLAN', 'plan' => 'PROFESSIONAL', 'amount_cents' => 30000];
        $changed = [...$original, 'amount_cents' => 45000];
        $catalog->append('professional', 0, $original, 'DRAFT', 1);
        $catalog->append('professional', 1, $original, 'PUBLISHED', 1);
        $working = $catalog->append('professional', 2, $changed, 'PUBLISHED', 1);

        $this->assertSame('DRAFT', $working['status']);
        $this->assertSame(30000, $catalog->published()['professional']['terms']['amount_cents']);
        $this->assertSame(45000, $catalog->find('professional')['terms']['amount_cents']);

        $catalog->append('professional', 3, $changed, 'PUBLISHED', 1);
        $this->assertSame(45000, $catalog->published()['professional']['terms']['amount_cents']);
        $history = $catalog->history('professional');
        $this->assertCount(4, $history);
        $this->assertSame(30000, $history[2]['terms']['amount_cents']);
    }

    public function test_archiving_a_working_version_withdraws_the_previous_publication(): void
    {
        $catalog = app(OfferCatalog::class);
        $terms = ['kind' => 'PLAN', 'plan' => 'PROFESSIONAL'];
        $catalog->append('professional', 0, $terms, 'DRAFT', 1);
        $catalog->append('professional', 1, $terms, 'PUBLISHED', 1);
        $catalog->append('professional', 2, $terms, 'DRAFT', 1);
        $catalog->append('professional', 3, $terms, 'ARCHIVED', 1);

        $this->assertArrayNotHasKey('professional', $catalog->published());
        $this->assertCount(4, $catalog->history('professional'));
    }

    public function test_a_new_offer_must_first_be_saved_as_a_draft(): void
    {
        $catalog = app(OfferCatalog::class);
        $record = $catalog->append('professional', 0, ['kind' => 'PLAN'], 'PUBLISHED', 1);

        $this->assertSame('DRAFT', $record['status']);
        $this->assertSame([], $catalog->published());
    }

    public function test_public_catalog_uses_the_live_version_until_a_working_copy_is_published(): void
    {
        $catalog = app(OfferCatalog::class);
        $terms = ['kind' => 'PLAN', 'name' => 'Profesional', 'plan' => 'PROFESSIONAL',
            'amount_cents' => 30000, 'duration_days' => 30, 'public' => true,
            'limits' => ['usuarios' => 3, 'lotes' => 50, 'analisis_ia' => 100]];
        $catalog->append('professional', 0, $terms, 'DRAFT', 1);
        $catalog->append('professional', 1, $terms, 'PUBLISHED', 1);
        $catalog->append('professional', 2, [...$terms, 'amount_cents' => 45000], 'DRAFT', 1);

        $offers = app(SubscriptionService::class)->publicOffers();
        $this->assertCount(1, $offers);
        $this->assertSame(30000, $offers[0]['amount_cents']);
        $this->assertSame(2, $offers[0]['version']);
    }

    public function test_paid_subscription_is_current_and_historical_order_is_not_enough(): void
    {
        Schema::create('solicitudes_acceso', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('id_usuario');
            $table->unsignedBigInteger('id_empresa');
            $table->string('estado');
            $table->string('selected_offer_key')->nullable();
            $table->json('selected_offer_snapshot')->nullable();
        });
        Schema::create('identity_billing_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('offer_key');
            $table->string('status');
        });
        Schema::create('suscripciones', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('id_empresa');
            $table->string('plan');
            $table->string('estado');
            $table->timestamp('vence_en');
        });
        $user = new User;
        $user->setRawAttributes(['id_usuario' => 7, 'id_empresa' => 9]);
        DB::table('solicitudes_acceso')->insert(['id_usuario' => 7, 'id_empresa' => 9, 'estado' => 'APROBADA',
            'selected_offer_key' => 'professional', 'selected_offer_snapshot' => json_encode(['amount_cents' => 30000])]);

        $onboarding = app(CompanyOnboarding::class);
        $this->assertSame('/settings/subscription?offer=professional', $onboarding->nextStep($user)['next']);
        $this->assertTrue($onboarding->nextStep($user)['requires_price_confirmation']);
        // Un pago antiguo no demuestra que exista una suscripción activa.
        DB::table('identity_billing_orders')->insert(['company_id' => 9, 'offer_key' => 'professional', 'status' => 'PAID']);
        $this->assertTrue($onboarding->nextStep($user)['requires_price_confirmation']);

        DB::table('suscripciones')->insert(['id_empresa' => 9, 'plan' => 'PROFESSIONAL',
            'estado' => 'ACTIVA', 'vence_en' => now()->addDay()]);
        $this->assertFalse($onboarding->nextStep($user)['requires_price_confirmation']);
        $this->assertSame('/dashboard', $onboarding->nextStep($user)['next']);

        DB::table('suscripciones')->where('id_empresa', 9)->update(['vence_en' => now()->subDay()]);
        $this->assertTrue($onboarding->nextStep($user)['requires_price_confirmation']);
        DB::table('suscripciones')->where('id_empresa', 9)
            ->update(['plan' => 'GRATUITO', 'vence_en' => now()->addDay()]);
        $this->assertTrue($onboarding->nextStep($user)['requires_price_confirmation']);
    }

    public function test_public_free_plan_uses_validated_identity_configuration(): void
    {
        config(['identity.free_plan.duration_days' => 30,
            'identity.free_plan.limits.usuarios' => 3,
            'identity.free_plan.limits.lotes' => 20,
            'identity.free_plan.limits.analisis_ia' => 0]);
        $this->assertSame([
            'duration_days' => 30,
            'limits' => ['usuarios' => 3, 'lotes' => 20, 'analisis_ia' => 0],
        ], app(SubscriptionService::class)->publicFreePlan());
    }
}
