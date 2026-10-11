<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/**
 * Uji kontrak: respons dicocokkan dengan skema docs/api/openapi.yaml untuk path, metode, dan status
 * yang sama (sukses maupun error). Respons yang tidak terdaftar di kontrak membuat tes gagal.
 */
trait KontrakApi
{
    private const URI_KONTRAK = 'https://kontrak.karsitara.invalid/openapi.json';

    /** @var array<string, mixed>|null */
    private static ?array $kontrak = null;

    private static ?Validator $validatorKontrak = null;

    /** @param  string  $path  path kontrak tanpa /api/v1, mis. /peran/{id}/izin */
    protected function assertSesuaiKontrak(TestResponse $respons, string $metode, string $path): TestResponse
    {
        $kontrak = $this->kontrak();
        $status = (string) $respons->getStatusCode();
        $operasi = $kontrak['paths'][$path][strtolower($metode)] ?? null;
        $this->assertNotNull($operasi, "Operasi $metode $path tidak ada di kontrak.");
        $this->assertArrayHasKey($status, $operasi['responses'], "Status $status untuk $metode $path tidak terdaftar di kontrak.");

        $penunjuk = '/paths/'.$this->escapePointer($path).'/'.strtolower($metode).'/responses/'.$status;
        $tanggapan = $operasi['responses'][$status];
        if (isset($tanggapan['$ref'])) {
            $penunjuk = substr($tanggapan['$ref'], 1);
            $tanggapan = $kontrak['components']['responses'][basename($tanggapan['$ref'])];
        }
        if (! isset($tanggapan['content']['application/json']['schema'])) {
            return $respons;
        }

        $hasil = $this->validatorKontrak()->validate(
            json_decode((string) $respons->getContent()),
            self::URI_KONTRAK.'#'.$penunjuk.'/content/application~1json/schema',
        );
        $galat = $hasil->error();
        $this->assertTrue($hasil->isValid(), "$metode $path $status tidak sesuai kontrak: "
            .($galat === null ? '' : $galat->message().' '.json_encode($galat->args()).' di '.json_encode($galat->data()->fullPath()))
            ."\n".$respons->getContent());

        return $respons;
    }

    /** @return array<string, mixed> */
    private function kontrak(): array
    {
        return self::$kontrak ??= Yaml::parseFile(base_path('../docs/api/openapi.yaml'));
    }

    private function validatorKontrak(): Validator
    {
        if (self::$validatorKontrak === null) {
            self::$validatorKontrak = new Validator;
            self::$validatorKontrak->setMaxErrors(1);
            self::$validatorKontrak->resolver()?->registerRaw(json_decode((string) json_encode($this->kontrak())), self::URI_KONTRAK);
        }

        return self::$validatorKontrak;
    }

    private function escapePointer(string $bagian): string
    {
        // JSON pointer di fragmen URI: ~ dan / di-escape (RFC 6901), lalu {id} dll. di-percent-encode.
        return rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $bagian));
    }
}
