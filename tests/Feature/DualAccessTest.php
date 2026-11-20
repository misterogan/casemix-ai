<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\BpjsTindakLanjut;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DualAccessTest extends TestCase
{
    use RefreshDatabase;

    private const TS = 'oem-poweredge-t320.tail2022f0.ts.net';

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->get('/_dual_probe', fn (\Illuminate\Http\Request $r) => response()->json([
            'secure' => $r->isSecure(), 'url' => route('login'),
        ]));
    }

    private function sessionCookie($res)
    {
        return collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
    }

    public function test_proxy_lokal_https_membuat_secure_dan_url_https(): void
    {
        $res = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => self::TS, 'X-Forwarded-Port' => '443'])
            ->get('/_dual_probe');
        $res->assertJson(['secure' => true, 'url' => 'https://'.self::TS.'/masuk']);
        $this->assertTrue($this->sessionCookie($res)->isSecure());
    }

    public function test_http_lokal_cookie_tanpa_secure_dan_login_berhasil(): void
    {
        $this->seed(RolePermissionSeeder::class);
        User::factory()->create(['email' => 'a@b.test', 'is_active' => true, 'password' => Hash::make('rahasia12345')])->assignRole('petugas');

        $server = ['REMOTE_ADDR' => '192.168.1.20'];
        $base = 'http://192.168.1.5:8300';
        $res = $this->withServerVariables($server)->get($base.'/_dual_probe');
        $res->assertJson(['secure' => false, 'url' => 'http://192.168.1.5:8300/masuk']);
        $this->assertFalse($this->sessionCookie($res)->isSecure());

        $this->withServerVariables($server)->post($base.'/masuk', ['email' => 'a@b.test', 'password' => 'rahasia12345'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_header_forwarded_dari_ip_lain_diabaikan(): void
    {
        $res = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'jahat.test'])
            ->get('/_dual_probe');
        $res->assertJson(['secure' => false]);
        $this->assertStringStartsWith('http://localhost', $res->json('url'));
        $this->assertFalse($this->sessionCookie($res)->isSecure());
    }

    public function test_docker_subnet_dipercaya_bila_trusted_proxies_diatur(): void
    {
        config(['trustedproxy.proxies' => ['127.0.0.1', '172.16.0.0/12']]);
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => self::TS, 'X-Forwarded-Port' => '8443'])
            ->get('/')->assertRedirect('https://'.self::TS.':8443/masuk');
    }

    public function test_docker_subnet_tidak_dipercaya_secara_bawaan(): void
    {
        $res = $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => self::TS])
            ->get('/');
        $this->assertStringStartsWith('http://localhost', $res->headers->get('Location'));
    }

    public function test_notifikasi_menyimpan_path_relatif(): void
    {
        $n = new BpjsTindakLanjut(3, 'http://localhost/bpjs?tab=klaim&status=pending');
        $this->assertSame('/bpjs?tab=klaim&status=pending', $n->toArray(new User)['url']);
        $this->assertSame('/bpjs?tab=klaim', (new BpjsTindakLanjut(1, '/bpjs?tab=klaim'))->url);
    }
}
