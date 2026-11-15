<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->create(['is_active' => true, 'password' => Hash::make('lama12345abc')])->assignRole('petugas');
    }

    public function test_halaman_tampil_dan_tamu_diarahkan_ke_login(): void
    {
        $this->get(route('account.password.edit'))->assertRedirect(route('login'));
        $this->put(route('account.password.update'), [])->assertRedirect(route('login'));
        $this->actingAs($this->user)->get(route('account.password.edit'))->assertOk()->assertSee('Simpan password');
    }

    public function test_password_lama_salah_ditolak(): void
    {
        $this->actingAs($this->user)->from(route('account.password.edit'))->put(route('account.password.update'), [
            'current_password' => 'salah12345x',
            'password' => 'baru12345abc',
            'password_confirmation' => 'baru12345abc',
        ])->assertSessionHasErrors(['current_password' => 'Password saat ini salah.']);

        $this->assertTrue(Hash::check('lama12345abc', $this->user->fresh()->password));
    }

    public function test_password_baru_tidak_cocok_atau_terlalu_pendek_ditolak(): void
    {
        $this->actingAs($this->user)->put(route('account.password.update'), [
            'current_password' => 'lama12345abc',
            'password' => 'baru12345abc',
            'password_confirmation' => 'beda12345abc',
        ])->assertSessionHasErrors('password');

        $this->actingAs($this->user)->put(route('account.password.update'), [
            'current_password' => 'lama12345abc',
            'password' => 'ab12',
            'password_confirmation' => 'ab12',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('lama12345abc', $this->user->fresh()->password));
    }

    public function test_berhasil_mengganti_dan_tercatat_di_audit(): void
    {
        $this->actingAs($this->user)->from(route('account.password.edit'))->put(route('account.password.update'), [
            'current_password' => 'lama12345abc',
            'password' => 'baru12345abc',
            'password_confirmation' => 'baru12345abc',
        ])->assertRedirect(route('account.password.edit'))->assertSessionHas('ok', 'Password berhasil diganti.');

        $log = AuditLog::where('action', 'akun.ganti_password')->sole();
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertStringNotContainsString('baru12345abc', json_encode($log->toArray()));
        $this->assertStringNotContainsString('lama12345abc', json_encode($log->toArray()));

        Auth::logout();
        $this->assertTrue(Auth::attempt(['email' => $this->user->email, 'password' => 'baru12345abc']));
    }
}
