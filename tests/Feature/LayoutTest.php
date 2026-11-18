<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kerangka tampilan: sidebar, topbar, mode gelap, dan halaman utama tiap peran. */
class LayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['is_active' => true])->assignRole($role);
    }

    public static function halamanUtama(): array
    {
        return [
            'koordinator' => ['koordinator', 'control.index'],
            'petugas' => ['petugas', 'folders.index'],
            'viewer' => ['viewer', 'folders.index'],
            'admin' => ['admin', 'admin.budget.index'],
        ];
    }

    /** @dataProvider halamanUtama */
    public function test_halaman_utama_tiap_peran_merender(string $role, string $route): void
    {
        $u = $this->user($role);
        $this->actingAs($u)->get(route('home'))->assertRedirect(route($route));

        $html = $this->actingAs($u)->get(route($route))->assertOk()
            ->assertSee('data-sidebar', false)
            ->assertSee('data-sidebar-toggle', false)
            ->assertSee('data-tema-toggle', false)
            ->assertSee($u->roleLabel())
            ->getContent();

        $this->assertMatchesRegularExpression('#>(Menu|Sistem)</h2>#', $html);
        $this->assertDoesNotMatchRegularExpression('/<(button|a|summary)\b[^>]*class="[^"]*\brounded-full\b/', $html,
            'Tombol aksi tidak boleh rounded-full (hanya badge/avatar).');
    }

    public function test_koordinator_punya_pencarian_klaim_dan_lonceng(): void
    {
        $this->actingAs($this->user('koordinator'))->get(route('control.index'))->assertOk()
            ->assertSee('id="cari-sep"', false)
            ->assertSee('Cari SEP, No. RM, nama…')
            ->assertSee('data-lonceng', false)
            ->assertSee(route('bpjs.index'), false)
            ->assertSee('Klaim BPJS')
            ->assertSee('Unggah manual');
    }

    public function test_admin_melihat_menu_sistem_bukan_menu_pasien(): void
    {
        $this->actingAs($this->user('admin'))->get(route('admin.budget.index'))->assertOk()
            ->assertSee('Administrasi')
            ->assertSee('Acuan regulasi')
            ->assertDontSee('Antrean kerja')
            ->assertDontSee('data-lonceng', false);
    }

    public function test_admin_tetap_403_di_halaman_pasien(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->get(route('control.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('work-queue.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('folders.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('bpjs.index', ['tab' => 'klaim']))->assertForbidden();
    }

    public function test_pilihan_mode_gelap_disimpan(): void
    {
        $html = $this->actingAs($this->user('viewer'))->get(route('folders.index'))->assertOk()->getContent();

        // Diterapkan sebelum render: baca localStorage (try/catch), hormati prefers-color-scheme bila belum ada pilihan.
        $this->assertStringContainsString("localStorage.getItem('tema')", $html);
        $this->assertStringContainsString('prefers-color-scheme: dark', $html);

        $js = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("[data-tema-toggle]", $js);
        $this->assertMatchesRegularExpression("/simpan\\('tema', html\\.classList\\.toggle\\('dark'\\)/", $js);
        $this->assertMatchesRegularExpression('/try \\{ localStorage\\.setItem/', $js);
    }

    public function test_login_tanpa_font_cdn(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('fonts.googleapis.com', false);
    }
}
