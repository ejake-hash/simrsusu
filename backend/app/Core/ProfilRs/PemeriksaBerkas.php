<?php

namespace App\Core\ProfilRs;

use App\Core\Http\ApiError;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;

/**
 * Berkas paket identitas yang boleh disimpan dan dikirim ke peramban (PRD 14.2): tipe dari isi berkas, bukan
 * nama atau Content-Type kiriman. SVG diperiksa dengan daftar izin (elemen SVG saja, tanpa skrip, handler,
 * rujukan keluar, PI, DOCTYPE/entity, atau CSS ber-escape) lalu **disimpan hasil serialisasi ulangnya**, bukan
 * byte kiriman, agar pengodean lain (mis. UTF-16) tidak bisa menyelundupkan isi yang tidak diperiksa.
 */
final class PemeriksaBerkas
{
    /** @var array<string, list<string>> jenis => tipe yang boleh */
    private const TIPE = [
        'logo' => ['image/svg+xml', 'image/png'],
        'motif' => ['image/svg+xml', 'image/png'],
        'foto' => ['image/webp', 'image/jpeg', 'image/png'],
    ];

    private const EKSTENSI = ['image/svg+xml' => 'svg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'];

    private const DIMENSI_MAKS = 4096;

    private const NS_SVG = 'http://www.w3.org/2000/svg';

    /** Elemen SVG statis untuk logo dan motif; selain ini ditolak. */
    private const ELEMEN_BOLEH = [
        'svg', 'g', 'defs', 'title', 'desc', 'metadata', 'symbol', 'use', 'path', 'rect', 'circle', 'ellipse', 'line',
        'polyline', 'polygon', 'text', 'tspan', 'textpath', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask',
        'pattern', 'style', 'filter', 'fegaussianblur', 'feoffset', 'feblend', 'fecolormatrix', 'fecomposite', 'feflood',
        'femerge', 'femergenode',
    ];

    /**
     * @return array{tipe: string, ekstensi: string, isi: string} isi = yang disimpan (SVG: hasil serialisasi ulang)
     */
    public function periksa(string $jenis, string $isi): array
    {
        $batas = (int) config("instalasi.identitas.batas_kb.$jenis") * 1024;
        if (strlen($isi) > $batas) {
            throw new ApiError(422, 'berkas_terlalu_besar', 'Berkas terlalu besar.', [
                ['kolom' => 'berkas', 'pesan' => sprintf('Maksimum %d KB untuk %s.', $batas / 1024, $jenis)],
            ]);
        }

        $tipe = $this->tipe($isi);
        if ($tipe === null || ! in_array($tipe, self::TIPE[$jenis], true)) {
            throw new ApiError(422, 'tipe_berkas_tidak_diizinkan', 'Jenis berkas tidak diizinkan.', [
                ['kolom' => 'berkas', 'pesan' => 'Yang diterima: '.implode(', ', array_map(fn ($t) => self::EKSTENSI[$t], self::TIPE[$jenis])).'.'],
            ]);
        }

        return ['tipe' => $tipe, 'ekstensi' => self::EKSTENSI[$tipe], 'isi' => $tipe === 'image/svg+xml' ? $this->svgAman($isi) : $isi];
    }

    private function tipe(string $isi): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($isi);
        if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            $ukuran = @getimagesizefromstring($isi);
            if ($ukuran === false || $ukuran['mime'] !== $mime) {
                return null;
            }
            if ($ukuran[0] > self::DIMENSI_MAKS || $ukuran[1] > self::DIMENSI_MAKS) {
                throw new ApiError(422, 'dimensi_terlalu_besar', 'Ukuran gambar terlalu besar.', [
                    ['kolom' => 'berkas', 'pesan' => sprintf('Maksimum %d×%d piksel.', self::DIMENSI_MAKS, self::DIMENSI_MAKS)],
                ]);
            }

            return $mime;
        }

        // SVG dikenali juga dalam UTF-16 (byte nol di sela huruf), lalu ditolak svgAman() karena bukan UTF-8.
        return preg_match('/<\x00?s\x00?v\x00?g\x00?[\s>]/i', $isi) === 1 ? 'image/svg+xml' : null;
    }

    /** @return string SVG hasil serialisasi ulang DOM yang lolos daftar izin */
    private function svgAman(string $isi): string
    {
        $dom = null;
        if (mb_check_encoding($isi, 'UTF-8') && ! str_starts_with($isi, "\xFE\xFF") && ! str_starts_with($isi, "\xFF\xFE")) {
            $dom = new DOMDocument;
            $sebelumnya = libxml_use_internal_errors(true);
            // Tanpa LIBXML_NOENT dan DTDLOAD: entity tidak diperluas, DTD luar tidak dimuat.
            $terurai = $dom->loadXML($isi, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($sebelumnya);
            if (! $terurai || $dom->doctype !== null || ! in_array(strtolower((string) $dom->encoding), ['', 'utf-8'], true)
                || $dom->documentElement?->localName !== 'svg' || ! $this->simpulAman($dom)) {
                $dom = null;
            }
        }

        $hasil = $dom?->saveXML($dom->documentElement);
        if (! is_string($hasil)) {
            throw new ApiError(422, 'berkas_tidak_aman', 'Berkas SVG memuat skrip atau rujukan luar dan tidak bisa dipakai.', [
                ['kolom' => 'berkas', 'pesan' => 'Simpan ulang SVG (UTF-8) tanpa skrip, tautan luar, objek tertanam, atau DOCTYPE.'],
            ]);
        }

        return $hasil;
    }

    private function simpulAman(DOMNode $simpul): bool
    {
        foreach ($simpul->childNodes as $anak) {
            if ($anak instanceof DOMProcessingInstruction) {
                return false;
            }
            if ($anak instanceof DOMElement && (! $this->elemenAman($anak) || ! $this->simpulAman($anak))) {
                return false;
            }
        }

        return true;
    }

    private function elemenAman(DOMElement $elemen): bool
    {
        $nama = strtolower((string) $elemen->localName);
        if ($elemen->namespaceURI !== self::NS_SVG || ! in_array($nama, self::ELEMEN_BOLEH, true)) {
            return false;
        }
        if ($nama === 'style' && ! $this->nilaiAman($elemen->textContent)) {
            return false;
        }

        foreach ($elemen->attributes ?? [] as $atribut) {
            $namaAtribut = strtolower((string) ($atribut->localName ?? $atribut->nodeName));
            $nilai = trim((string) $atribut->nodeValue);
            if (str_starts_with($namaAtribut, 'on') || $namaAtribut === 'base'
                || ($namaAtribut === 'href' && ! str_starts_with($nilai, '#'))
                || ! $this->nilaiAman($nilai)) {
                return false;
            }
        }

        return true;
    }

    /** Nilai atribut atau CSS: tanpa escape CSS, skema skrip/data, @import, image-set(), atau url() ke luar dokumen. */
    private function nilaiAman(string $nilai): bool
    {
        $kecil = strtolower($nilai);

        return ! str_contains($kecil, '\\') && ! str_contains($kecil, 'javascript:') && ! str_contains($kecil, 'data:')
            && ! str_contains($kecil, '@import') && ! str_contains($kecil, 'expression(') && ! str_contains($kecil, 'image-set')
            && preg_match('/url\(\s*[\'"]?(?!#)/', $kecil) !== 1;
    }
}
