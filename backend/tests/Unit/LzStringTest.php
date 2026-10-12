<?php

namespace Tests\Unit;

use App\Modules\Integrasi\Infrastructure\LzString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port LzString cocok dengan pustaka JS lz-string 1.5.0 (vektor dibuat dengan compressToEncodedURIComponent resmi),
 * termasuk karakter multi-bita dan pasangan surrogate.
 */
class LzStringTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    public static function vektor(): array
    {
        return [
            ['', 'Q'],
            ['a', 'IZA'],
            ['Sukses', 'MoVw1gzgphQ'],
            ['{"list":[{"kode":"11","nama":"ACEH"},{"kode":"12","nama":"SUMATERA UTARA"}]}', 'N4IgNglgzgLiBcBtUBrA9gEwKYJARjxABoQA7AQwFtzcBBAYQFEAJEAXyNUx3nwCZiZKjV4BlAKoBZWgBVGAJVoACcTNqL2AXTZA'],
            ['ááá ÿ 日本語 😀 aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'IcgEH-UU9NBpzQ8qNIXg3AAe6AhhzXs93vQA'],
            ['{"peserta":{"noKartu":"0001234567890","nama":"Pasien Sintetis","hakKelas":{"kode":"3","keterangan":"KELAS III"}}}', 'N4IgDgpgzhBOAuBDEAuUA7A9gaUQgrqiAAykCMATAMwAsArAGwDsAHAJzEgA0I6iAtshQgACoigBLCOgAEAZQnp4EeBKjcQAC0QBrbBAA241KB2YAJhCJUNOlXEToA5o6LYAogBkAgnJkBJQJAAX1CgA'],
            ['abababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababababab', 'IYI17SOrZ+6IcpqXrZj2u5-vQg4o0k8syi6q2m+uxh5p1l9tzj7r3n-voIHChokeLGSJ0qbJnzZQA'],
        ];
    }

    #[DataProvider('vektor')]
    public function test_kompres_dan_dekompres_sama_dengan_pustaka_js(string $asli, string $js): void
    {
        $this->assertSame($js, LzString::kompres($asli));
        $this->assertSame($asli, LzString::dekompres($js));
    }

    public function test_masukan_kosong(): void
    {
        $this->assertNull(LzString::dekompres(''));
    }
}
