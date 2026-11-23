<?php

namespace Tests\Unit;

use App\Services\Eklaim\EklaimClient;
use App\Services\Eklaim\EklaimCrypto;
use App\Services\Eklaim\EklaimException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Enkripsi E-Klaim (inacbg_encrypt/decrypt) dan whitelist metode READ-ONLY. */
class EklaimClientTest extends TestCase
{
    private const KEY = '7a1f3c9e5b2d8f4a6c0e1b3d5f7a9c2e4b6d8f0a1c3e5b7d9f2a4c6e8b0d2f4a';

    public function test_enkripsi_round_trip(): void
    {
        $c = new EklaimCrypto(self::KEY);
        $plain = json_encode(['metadata' => ['method' => 'get_claim_data'], 'data' => ['nomor_sep' => '0301R0011116V000001']]);
        $enc = $c->encrypt($plain);

        $this->assertSame($plain, $c->decrypt($enc));
        $this->assertNotSame($enc, $c->encrypt($plain), 'IV harus acak');
        $this->assertSame($plain, $c->decrypt(EklaimCrypto::potong("----BEGIN ENCRYPTED DATA----\n{$enc}----END ENCRYPTED DATA----\n")));
    }

    public function test_signature_salah_ditolak(): void
    {
        $c = new EklaimCrypto(self::KEY);
        $raw = base64_decode(preg_replace('/\s+/', '', $c->encrypt('{"a":1}')));
        $raw[0] = chr(ord($raw[0]) ^ 1);

        $this->expectException(EklaimException::class);
        $this->expectExceptionMessage('Signature');
        $c->decrypt(base64_encode($raw));
    }

    public function test_key_lain_ditolak(): void
    {
        $enc = (new EklaimCrypto(self::KEY))->encrypt('{"a":1}');

        $this->expectException(EklaimException::class);
        (new EklaimCrypto(str_repeat('ab', 32)))->decrypt($enc);
    }

    public function test_key_tidak_valid_ditolak_tanpa_mencetak_key(): void
    {
        try {
            new EklaimCrypto('rahasia123');
            $this->fail('Harus exception');
        } catch (EklaimException $e) {
            $this->assertStringNotContainsString('rahasia123', $e->getMessage());
        }
    }

    public static function metodeTerlarang(): array
    {
        return array_map(fn ($m) => [$m], ['new_claim', 'set_claim_data', 'update_patient', 'delete_patient', 'grouper', 'claim_final',
            'reedit_claim', 'send_claim', 'send_claim_individual', 'delete_claim', 'claim_print', 'GET_CLAIM_DATA', '']);
    }

    /** @dataProvider metodeTerlarang */
    #[\PHPUnit\Framework\Attributes\DataProvider('metodeTerlarang')]
    public function test_metode_selain_get_claim_data_ditolak(string $m): void
    {
        Http::fake();
        config(['services.eklaim.ws_url' => 'http://e-klaim.test/ws.php', 'services.eklaim.encryption_key' => self::KEY]);

        try {
            (new EklaimClient)->call($m, ['nomor_sep' => 'X']);
            $this->fail("Metode $m seharusnya ditolak");
        } catch (EklaimException $e) {
            $this->assertStringContainsString('tidak diizinkan', $e->getMessage());
        }
        Http::assertNothingSent();
    }
}
