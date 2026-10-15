<?php

declare(strict_types=1);

use App\Modules\Platform\Application\Branding\DomainVerifier;
use App\Modules\Platform\Application\ProvisionAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Reemplaza la verificación DNS por una respuesta fija.
 *
 * @param bool $answer Resultado que devolverá la verificación.
 */
function fakeDomainVerifier(bool $answer): void
{
    app()->instance(DomainVerifier::class, new class($answer) extends DomainVerifier
    {
        public function __construct(private readonly bool $answer) {}

        public function hasToken(string $domain, string $token): bool
        {
            return $this->answer;
        }
    });
}

beforeEach(fn () => Storage::fake('local'));

it('guarda nombre comercial, contacto y pie, y los devuelve en la marca', function (): void {
    $token = ownerToken();

    $data = $this->withToken($token)->putJson('/api/v1/branding', [
        'brand_name' => 'Juguetería Caserito', 'brand_color' => '#e11d48', 'brand_tagline' => 'Sonrisas',
        'brand_email' => 'hola@jugueteria.pe', 'brand_phone' => '999 111 222', 'brand_footer' => 'Gracias por tu compra',
        'brand_powered_by' => true,
    ])->assertOk()->json('data');

    expect($data['name'])->toBe('Juguetería Caserito')->and($data['email'])->toBe('hola@jugueteria.pe')
        ->and($data['footer'])->toBe('Gracias por tu compra')->and($data['powered_by'])->toBeTrue();
    $this->withToken($token)->putJson('/api/v1/branding', ['brand_email' => 'no-es-correo'])->assertStatus(422);
});

it('sube el logo, lo sirve sin sesión y lo manda a eFact', function (): void {
    $token = ownerToken();
    $company = $this->withToken($token)->postJson('/api/v1/companies', [
        'legal_name' => 'Jugueteria SAC', 'tax_id' => '20601234565',
    ])->json('data.id');
    $this->withToken($token)->putJson("/api/v1/fiscal/settings/{$company}", [
        'base_url' => 'https://efact.test', 'environment' => 'beta', 'api_key' => 'key-123',
        'api_secret' => 'secret-456', 'webhook_secret' => 'whsec_test', 'boleta_series' => 'B001', 'invoice_series' => 'F001',
    ])->assertOk();
    Http::fake(['efact.test/*' => Http::response(['estado' => 'exito', 'datos' => ['logo_url' => 'x']], 200)]);

    $result = $this->withToken($token)->post('/api/v1/branding/assets/logo', [
        'file' => UploadedFile::fake()->image('logo.png', 400, 120),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data');

    expect($result['efact'])->toHaveCount(1)->and($result['efact'][0]['synced'])->toBeTrue();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/empresa/logo')
        && $request->hasHeader('X-Api-Key', 'key-123'));

    $brand = $this->withToken($token)->getJson('/api/v1/branding')->assertOk()->json('data');
    expect($brand['logo_url'])->toStartWith('/api/v1/public/brand/');
    $this->get($brand['logo_url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('rechaza un icono no cuadrado y un archivo que no es imagen', function (): void {
    $token = ownerToken();

    $this->withToken($token)->post('/api/v1/branding/assets/icon', [
        'file' => UploadedFile::fake()->image('icon.png', 300, 150),
    ], ['Accept' => 'application/json'])->assertStatus(422);
    $this->withToken($token)->post('/api/v1/branding/assets/logo', [
        'file' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertStatus(422);
    $this->withToken($token)->post('/api/v1/branding/assets/banner', [
        'file' => UploadedFile::fake()->image('b.png', 400, 120),
    ], ['Accept' => 'application/json'])->assertStatus(404);
});

it('elimina un logo y deja de servirse', function (): void {
    $token = ownerToken();
    $this->withToken($token)->post('/api/v1/branding/assets/logo_dark', [
        'file' => UploadedFile::fake()->image('dark.png', 400, 120),
    ], ['Accept' => 'application/json'])->assertCreated();
    $url = $this->withToken($token)->getJson('/api/v1/branding')->json('data.logo_dark_url');
    $this->get($url)->assertOk();

    $this->withToken($token)->deleteJson('/api/v1/branding/assets/logo_dark')->assertOk();

    $this->get($url)->assertStatus(404);
    expect($this->withToken($token)->getJson('/api/v1/branding')->json('data.logo_dark_url'))->toBeNull();
});

it('el dominio propio solo resuelve la marca después de verificarlo', function (): void {
    $token = ownerToken();
    $this->withToken($token)->putJson('/api/v1/branding', ['brand_name' => 'Mi Tienda', 'brand_color' => '#0ea5e9'])->assertOk();
    $domain = $this->withToken($token)->putJson('/api/v1/branding/domain', ['domain' => 'Tienda.MiNegocio.pe'])
        ->assertOk()->json('data');
    expect($domain['name'])->toBe('tienda.minegocio.pe')->and($domain['record']['host'])->toBe('_caserito.tienda.minegocio.pe')
        ->and($domain['verified_at'])->toBeNull();

    $this->getJson('/api/v1/public/brand?domain=tienda.minegocio.pe')->assertOk()->assertJsonPath('data.custom', false);

    fakeDomainVerifier(false);
    $this->withToken($token)->postJson('/api/v1/branding/domain/verify')->assertStatus(422)
        ->assertJsonPath('code', 'domain_not_verified');

    fakeDomainVerifier(true);
    $this->withToken($token)->postJson('/api/v1/branding/domain/verify')->assertOk();

    $this->getJson('/api/v1/public/brand?domain=tienda.minegocio.pe')->assertOk()
        ->assertJsonPath('data.custom', true)->assertJsonPath('data.name', 'Mi Tienda')
        ->assertJsonPath('data.color', '#0ea5e9');
});

it('un dominio no puede usarse en dos cuentas ni tener formato inválido', function (): void {
    $first = ownerToken();
    $this->withToken($first)->putJson('/api/v1/branding/domain', ['domain' => 'tienda.uno.pe'])->assertOk();
    app(ProvisionAccount::class)->handle('Otra Cuenta', 'Beto', 'beto@example.test', 'clave-12345');
    $second = loginToken('beto@example.test', 'clave-12345');

    $this->withToken($second)->putJson('/api/v1/branding/domain', ['domain' => 'tienda.uno.pe'])->assertStatus(422);
    $this->withToken($second)->putJson('/api/v1/branding/domain', ['domain' => 'https://tienda.dos.pe/'])->assertStatus(422);
    $this->withToken($second)->putJson('/api/v1/branding/domain', ['domain' => null])->assertOk()
        ->assertJsonPath('data.name', null);
});
