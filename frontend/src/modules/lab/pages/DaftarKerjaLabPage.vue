<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { computed, nextTick, ref } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tabs from '@/design-system/components/Tabs.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { errorKolom } from '@/modules/akses/pengaturan'
import { INTERVAL_LAYAR_KERJA } from '@/modules/notifikasi/polling'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import { ambilSpesimen, butirTerbaru, inputHasilLab, terimaSpesimen, validasiHasilLab } from '../api'
import DialogAdendumHasilLab from '../components/DialogAdendumHasilLab.vue'
import SeksiDaftarKerja from '../components/SeksiDaftarKerja.vue'
import TampilanHasilLab from '../components/TampilanHasilLab.vue'
import {
  bacaIsianHasil,
  bolehValidasi,
  hasilBerubah,
  identitasPasien,
  KUNCI_LAB,
  kurangJenisSpesimen,
  LABEL_LOKASI_PENGAMBILAN,
  LABEL_PENANDA,
  LABEL_TAHAP,
  perluMuatUlang,
  pesanGalatLab,
  tahapTersedia,
  teksLokasi,
  teksNilai,
} from '../lab'
import type { BarisDaftarKerjaLab, ButirDaftarKerjaLab, HasilLab, IsianHasilLab, LokasiPengambilanSpesimen, TahapDaftarKerjaLab } from '../tipe'

/**
 * Penunjang → Laboratorium: daftar kerja lab (tiket 2.30 spesimen, 2.31 hasil; FR-19-01..06, 7.00.5 butir 2–4). Tahap
 * Spesimen (ambil sampel, terima di lab), Hasil (input, input ulang, validasi analis), Otorisasi (Sp.PK); tab tampil
 * menurut izin sesi, tombol hanya menurut `dapat` dari server. Validasi, otorisasi, dan adendum dikonfirmasi dengan
 * identitas dua penanda (5.1 butir 9). Penanda hasil dihitung server.
 */
const qc = useQueryClient()
const segarkan = () => qc.invalidateQueries({ queryKey: KUNCI_LAB.semua })

const daftarTahap = computed(() => tahapTersedia(boleh).map((t) => ({ nilai: t, label: LABEL_TAHAP[t] })))
const tahap = ref<string>(daftarTahap.value[0]!.nilai)
const TEKS_TAHAP: Record<TahapDaftarKerjaLab, { cito: string; rutin: string; kosongCito: string; kosongRutin: string }> = {
  spesimen: {
    cito: 'Kerjakan lebih dulu. Order keluar dari tahap ini sesudah semua pemeriksaannya diterima di lab.',
    rutin: 'Order rutin, terlama di atas.',
    kosongCito: 'Tidak ada order cito yang menunggu.',
    kosongRutin: 'Tidak ada order rutin yang menunggu. Order muncul sesudah dokter mengirim order laboratorium.',
  },
  hasil: {
    cito: 'Spesimen sudah diterima. Input hasil lalu validasi; hasil bisa diinput ulang selama belum divalidasi.',
    rutin: 'Order rutin dengan spesimen yang sudah diterima, terlama di atas.',
    kosongCito: 'Tidak ada hasil cito yang menunggu input atau validasi.',
    kosongRutin: 'Tidak ada hasil rutin yang menunggu input atau validasi.',
  },
  otorisasi: {
    cito: 'Hasil yang sudah divalidasi analis dan butuh otorisasi Sp.PK sebelum keluar.',
    rutin: 'Hasil rutin yang menunggu otorisasi Sp.PK, terlama di atas.',
    kosongCito: 'Tidak ada hasil cito yang menunggu otorisasi.',
    kosongRutin: 'Tidak ada hasil rutin yang menunggu otorisasi.',
  },
}

/** Hasil aksi terakhir (barcode, hasil tersimpan): tetap tampil sampai aksi berikutnya. */
const hasil = ref<string | null>(null)
const elHasil = ref<HTMLElement | null>(null)
let fokusHasil = false
const fokusSesudahTutup = () => (fokusHasil ? elHasil.value : null)
async function berhasil(pesan: string) {
  hasil.value = pesan
  fokusHasil = true
  await nextTick()
  await segarkan()
}

// ── Ambil sampel ──
const sasaranAmbil = ref<{ baris: BarisDaftarKerjaLab; butir: ButirDaftarKerjaLab } | null>(null)
const bukaAmbil = ref(false)
const jenis = ref('')
const lokasi = ref<LokasiPengambilanSpesimen | ''>('')
const galatAmbil = ref<unknown>(null)
const mengambil = ref(false)
const sudahCoba = ref(false)
function mintaAmbil(baris: BarisDaftarKerjaLab, butir: ButirDaftarKerjaLab) {
  sasaranAmbil.value = { baris, butir }
  jenis.value = ''
  lokasi.value = ''
  galatAmbil.value = null
  sudahCoba.value = false
  fokusHasil = false
  bukaAmbil.value = true
}
const kurangJenis = computed(() => kurangJenisSpesimen(jenis.value))
const errorJenis = computed(() => errorKolom(galatAmbil.value, ['jenis']).jenis ?? (sudahCoba.value ? kurangJenis.value : null))
const errorLokasi = computed(() => errorKolom(galatAmbil.value, ['lokasi_pengambilan']).lokasi_pengambilan ?? (sudahCoba.value && !lokasi.value ? 'Pilih lokasi pengambilan.' : null))
const pesanAmbil = computed(() => (galatAmbil.value && !errorKolom(galatAmbil.value, ['jenis', 'lokasi_pengambilan']).jenis && !errorKolom(galatAmbil.value, ['jenis', 'lokasi_pengambilan']).lokasi_pengambilan ? pesanGalatLab(galatAmbil.value) : null))
async function ambil() {
  const s = sasaranAmbil.value
  sudahCoba.value = true
  if (!s || kurangJenis.value || !lokasi.value) return
  mengambil.value = true
  galatAmbil.value = null
  try {
    const sp = await ambilSpesimen(s.butir.id, { jenis: jenis.value.trim(), lokasi_pengambilan: lokasi.value })
    bukaAmbil.value = false
    await berhasil(`Sampel ${s.butir.nama} untuk ${s.baris.pasien.nama} tercatat. Barcode ${sp.barcode}.`)
  } catch (e) {
    // Data basi (butir batal, sampel sudah diambil petugas lain): daftar dimuat ulang dulu, pesan server tetap di dialog.
    if (perluMuatUlang(e)) await segarkan()
    galatAmbil.value = e
  } finally {
    mengambil.value = false
  }
}

// ── Terima di lab ──
const sasaranTerima = ref<{ baris: BarisDaftarKerjaLab; butir: ButirDaftarKerjaLab } | null>(null)
const bukaTerima = ref(false)
const galatTerima = ref<unknown>(null)
const menerima = ref(false)
function mintaTerima(baris: BarisDaftarKerjaLab, butir: ButirDaftarKerjaLab) {
  sasaranTerima.value = { baris, butir }
  galatTerima.value = null
  fokusHasil = false
  bukaTerima.value = true
}
async function terima() {
  const s = sasaranTerima.value
  if (!s?.butir.spesimen) return
  menerima.value = true
  galatTerima.value = null
  try {
    const sp = await terimaSpesimen(s.butir.spesimen.id)
    bukaTerima.value = false
    await berhasil(`Spesimen ${sp.barcode} (${s.butir.nama}, ${s.baris.pasien.nama}) diterima di lab. Order ini tidak bisa lagi dibatalkan pemesan.`)
  } catch (e) {
    if (perluMuatUlang(e)) await segarkan()
    galatTerima.value = e
  } finally {
    menerima.value = false
  }
}

const ringkasHasil = (h: HasilLab) => (h.penanda ? `${teksNilai(h)}, ${LABEL_PENANDA[h.penanda]}` : teksNilai(h))

// ── Input hasil (dan input ulang sebelum validasi) ──
const sasaranInput = ref<{ baris: BarisDaftarKerjaLab; butir: ButirDaftarKerjaLab } | null>(null)
const bukaInput = ref(false)
const nilaiInput = ref('')
const catatanInput = ref('')
const galatInput = ref<unknown>(null)
const galatIsian = ref<string | null>(null)
const menyimpan = ref(false)
const jenisInput = computed(() => sasaranInput.value?.butir.hasil?.jenis_hasil ?? null)
function mintaInput(baris: BarisDaftarKerjaLab, butir: ButirDaftarKerjaLab) {
  sasaranInput.value = { baris, butir }
  nilaiInput.value = ''
  catatanInput.value = butir.hasil?.catatan ?? ''
  galatInput.value = null
  galatIsian.value = null
  fokusHasil = false
  bukaInput.value = true
}
const petunjukInput = computed(() => {
  const h = sasaranInput.value?.butir.hasil
  if (h?.jenis_hasil === 'teks') return 'Teks hasil, paling banyak 500 karakter.'
  if (h?.jenis_hasil === 'numerik') return `Angka, koma untuk desimal${h.satuan ? `. Satuan ${h.satuan}` : ''}.`
  return 'Angka (koma untuk desimal) atau teks, sesuai jenis pemeriksaan. Satuan dan penanda diisi server dari data induk RS.'
})
const kolomInput = computed(() => errorKolom(galatInput.value, ['nilai', 'nilai_teks', 'catatan']))
const errorNilaiInput = computed(() => galatIsian.value ?? kolomInput.value.nilai ?? kolomInput.value.nilai_teks ?? null)
const pesanInput = computed(() => (galatInput.value && !errorNilaiInput.value && !kolomInput.value.catatan ? pesanGalatLab(galatInput.value) : null))
/** Jenis hasil belum diketahui dan server menjawab "berupa teks": kirim ulang sekali sebagai teks. */
const mintaTeks = (e: unknown) => e instanceof ApiError && e.kode === 'validasi_gagal' && e.rincian.some((r) => r.kolom === 'nilai_teks')
async function simpanInput() {
  const s = sasaranInput.value
  galatInput.value = null
  const baca = bacaIsianHasil(nilaiInput.value, jenisInput.value)
  galatIsian.value = 'galat' in baca ? baca.galat : null
  if (!s || 'galat' in baca) return
  menyimpan.value = true
  const catatan = catatanInput.value.trim() || null
  try {
    let h: HasilLab
    try {
      h = await inputHasilLab(s.butir.id, { ...baca.isian, catatan })
    } catch (e) {
      if (jenisInput.value !== null || baca.isian.nilai === undefined || !mintaTeks(e)) throw e
      const ulang: IsianHasilLab = { nilai_teks: nilaiInput.value.trim(), catatan }
      h = await inputHasilLab(s.butir.id, ulang)
    }
    bukaInput.value = false
    await berhasil(`Hasil ${s.butir.nama} untuk ${s.baris.pasien.nama} tersimpan: ${ringkasHasil(h)}. Menunggu validasi.`)
  } catch (e) {
    if (perluMuatUlang(e)) await segarkan()
    galatInput.value = e
  } finally {
    menyimpan.value = false
  }
}

// ── Validasi analis / otorisasi Sp.PK ──
const sasaranValidasi = ref<{ baris: BarisDaftarKerjaLab; butir: ButirDaftarKerjaLab; hasil: HasilLab } | null>(null)
const bukaValidasi = ref(false)
const galatValidasi = ref<unknown>(null)
const memvalidasi = ref(false)
const otorisasi = computed(() => sasaranValidasi.value?.hasil.status === 'menunggu_otorisasi')
/** Hasil dimuat ulang dari server saat dialog dibuka; tombol konfirmasi menunggu muat ulang selesai. */
const memuatValidasi = ref(false)
const hasilBerubahPesan = ref<string | null>(null)
const tidakBisaValidasi = ref<string | null>(null)
/** Muat ulang gagal (jaringan, 5xx): konfirmasi ditahan sampai "Coba lagi" berhasil. */
const galatMuatValidasi = ref<unknown>(null)
let nomorMuat = 0
function mintaValidasi(baris: BarisDaftarKerjaLab, butir: ButirDaftarKerjaLab) {
  if (!butir.hasil) return
  sasaranValidasi.value = { baris, butir, hasil: butir.hasil }
  galatValidasi.value = null
  fokusHasil = false
  bukaValidasi.value = true
  void muatUlangValidasi(baris, butir, butir.hasil)
}
function cobaMuatValidasi() {
  const s = sasaranValidasi.value
  if (s) void muatUlangValidasi(s.baris, s.butir, s.hasil)
}
async function muatUlangValidasi(baris: BarisDaftarKerjaLab, butir: ButirDaftarKerjaLab, tampil: HasilLab) {
  hasilBerubahPesan.value = null
  tidakBisaValidasi.value = null
  galatMuatValidasi.value = null
  memuatValidasi.value = true
  const nomor = ++nomorMuat
  try {
    const tahapButir = butir.status === 'menunggu_otorisasi' ? 'otorisasi' : 'hasil'
    const baru = await butirTerbaru(baris.prioritas, tahapButir, baris.id, butir.id)
    if (nomor !== nomorMuat) return
    if (!baru?.hasil || !bolehValidasi(baru)) {
      tidakBisaValidasi.value = 'Hasil ini sudah tidak menunggu tindakan Anda (sudah diproses atau dibatalkan). Daftar kerja sudah dimuat ulang.'
      await segarkan()
      return
    }
    sasaranValidasi.value = { baris, butir: baru, hasil: baru.hasil }
    if (hasilBerubah(tampil, baru.hasil)) {
      hasilBerubahPesan.value = `Hasil berubah sejak daftar terakhir dimuat. Tadi: ${ringkasHasil(tampil)}. Sekarang: ${ringkasHasil(baru.hasil)}. Periksa lagi sebelum melanjutkan.`
      void segarkan()
    }
  } catch (e) {
    if (nomor === nomorMuat) galatMuatValidasi.value = e
  } finally {
    if (nomor === nomorMuat) memuatValidasi.value = false
  }
}
const uraianValidasi = computed(() => {
  const h = sasaranValidasi.value?.hasil
  if (!h) return ''
  if (h.status === 'menunggu_otorisasi') return 'Sesudah diotorisasi, hasil keluar ke pemesan dan penanggung jawab pasien dan tidak bisa diubah lagi. Koreksi lewat adendum.'
  if (h.butuh_otorisasi_spk) return 'Pemeriksaan ini butuh otorisasi Sp.PK. Sesudah divalidasi, hasil menunggu otorisasi oleh Sp.PK lain.'
  return 'Sesudah divalidasi, hasil keluar ke pemesan dan penanggung jawab pasien dan tidak bisa diubah lagi. Koreksi lewat adendum.'
})
async function validasi() {
  const s = sasaranValidasi.value
  if (!s || memuatValidasi.value || tidakBisaValidasi.value || galatMuatValidasi.value) return
  memvalidasi.value = true
  galatValidasi.value = null
  try {
    const h = await validasiHasilLab(s.hasil.id)
    bukaValidasi.value = false
    const akhir = h.status === 'divalidasi' ? 'Hasil keluar ke pemesan dan penanggung jawab pasien.' : 'Menunggu otorisasi Sp.PK.'
    await berhasil(`Hasil ${s.butir.nama} untuk ${s.baris.pasien.nama} ${otorisasi.value ? 'diotorisasi' : 'divalidasi'}. ${akhir}`)
  } catch (e) {
    if (perluMuatUlang(e)) await segarkan()
    galatValidasi.value = e
  } finally {
    memvalidasi.value = false
  }
}

// ── Adendum ──
const sasaranAdendum = ref<HasilLab | null>(null)
const bukaAdendum = ref(false)
function mintaAdendum(_baris: BarisDaftarKerjaLab, butir: ButirDaftarKerjaLab) {
  if (!butir.hasil) return
  sasaranAdendum.value = butir.hasil
  fokusHasil = false
  bukaAdendum.value = true
}
async function adendumSelesai(h: HasilLab) {
  const a = h.adendum[h.adendum.length - 1]
  await berhasil(`Adendum ${h.nama} untuk ${h.pasien.nama} tercatat${a ? `: ${ringkasHasil({ ...h, ...a })}` : ''}. Hasil asli tetap tersimpan.`)
}
</script>

<template>
  <HalamanModul
    izin="penunjang.laboratorium.lihat"
    judul="Daftar kerja laboratorium"
    :uraian="`Order lab aktif dari semua pos. Cito di atas, lalu rutin; terlama di atas. Diperbarui otomatis tiap ${INTERVAL_LAYAR_KERJA / 1000} detik.`"
  >
    <div class="tumpukan">
      <div v-if="hasil" ref="elHasil" class="hasil" tabindex="-1">
        <Pesan jenis="ok" role="status">{{ hasil }}</Pesan>
      </div>
      <Tabs v-model="tahap" label="Tahap" :daftar="daftarTahap">
        <template v-for="t in daftarTahap" :key="t.nilai" #[t.nilai]>
          <div class="tumpukan">
            <SeksiDaftarKerja
              prioritas="cito"
              judul="Cito"
              :tahap="t.nilai"
              :uraian="TEKS_TAHAP[t.nilai].cito"
              :teks-kosong="TEKS_TAHAP[t.nilai].kosongCito"
              @ambil="mintaAmbil"
              @terima="mintaTerima"
              @input="mintaInput"
              @validasi="mintaValidasi"
              @adendum="mintaAdendum"
            />
            <SeksiDaftarKerja
              prioritas="rutin"
              judul="Rutin"
              :tahap="t.nilai"
              :uraian="TEKS_TAHAP[t.nilai].rutin"
              :teks-kosong="TEKS_TAHAP[t.nilai].kosongRutin"
              @ambil="mintaAmbil"
              @terima="mintaTerima"
              @input="mintaInput"
              @validasi="mintaValidasi"
              @adendum="mintaAdendum"
            />
          </div>
        </template>
      </Tabs>
    </div>

    <Dialog
      v-model:open="bukaAmbil"
      :judul="sasaranAmbil ? `Ambil sampel ${sasaranAmbil.butir.nama}` : 'Ambil sampel'"
      uraian="Cocokkan identitas pasien dengan gelang identitas sebelum mengambil sampel. Jam pengambilan dicatat server."
      lebar="lebar"
      :sibuk="mengambil"
      :fokus-sesudah-tutup="fokusSesudahTutup"
    >
      <dl v-if="sasaranAmbil" class="ringkas">
        <div>
          <dt>Pasien</dt>
          <dd>{{ identitasPasien(sasaranAmbil.baris) }}</dd>
        </div>
        <div>
          <dt>Lokasi sekarang</dt>
          <dd>{{ teksLokasi(sasaranAmbil.baris) }}</dd>
        </div>
        <div>
          <dt>Pemeriksaan</dt>
          <dd><code>{{ sasaranAmbil.butir.kode_item }}</code> {{ sasaranAmbil.butir.nama }}</dd>
        </div>
      </dl>
      <form id="form-ambil-sampel" class="form" novalidate @submit.prevent="ambil">
        <KolomIsian v-slot="a" label="Jenis spesimen" wajib petunjuk="Mis. darah vena, urin, swab. 2 sampai 100 karakter." :error="errorJenis ?? undefined">
          <input v-bind="a" v-model="jenis" type="text" class="isian" maxlength="100" autocomplete="off" />
        </KolomIsian>
        <fieldset class="pilihan-lokasi">
          <legend class="kolom-label">Lokasi pengambilan <span class="kolom-wajib" aria-hidden="true">*</span></legend>
          <label v-for="(label, kode) in LABEL_LOKASI_PENGAMBILAN" :key="kode" class="pilihan" :class="{ dipilih: lokasi === kode }">
            <input v-model="lokasi" type="radio" name="lokasi-pengambilan" :value="kode" :aria-invalid="errorLokasi ? 'true' : undefined" :aria-describedby="errorLokasi ? 'galat-lokasi' : undefined" />
            <span>{{ label }}</span>
          </label>
          <p v-if="errorLokasi" id="galat-lokasi" class="galat">{{ errorLokasi }}</p>
        </fieldset>
      </form>
      <div role="alert">
        <div v-if="pesanAmbil" class="blok-galat">
          <Pesan jenis="error">{{ pesanAmbil }}</Pesan>
        </div>
      </div>
      <template #aksi>
        <Tombol :disabled="mengambil || undefined" @click="bukaAmbil = false">Batal</Tombol>
        <Tombol type="submit" form="form-ambil-sampel" varian="utama" :sibuk="mengambil">Catat sampel dan buat barcode</Tombol>
      </template>
    </Dialog>

    <Dialog
      v-model:open="bukaTerima"
      :judul="sasaranTerima ? `Terima spesimen ${sasaranTerima.butir.spesimen?.barcode ?? ''}`.trim() : 'Terima spesimen'"
      uraian="Cocokkan barcode dan identitas pada label spesimen. Sesudah diterima, pemesan tidak bisa membatalkan order ini."
      lebar="lebar"
      :sibuk="menerima"
      :fokus-sesudah-tutup="fokusSesudahTutup"
    >
      <dl v-if="sasaranTerima" class="ringkas">
        <div>
          <dt>Pasien</dt>
          <dd>{{ identitasPasien(sasaranTerima.baris) }}</dd>
        </div>
        <div>
          <dt>Pemeriksaan</dt>
          <dd><code>{{ sasaranTerima.butir.kode_item }}</code> {{ sasaranTerima.butir.nama }}</dd>
        </div>
        <div v-if="sasaranTerima.butir.spesimen">
          <dt>Spesimen</dt>
          <dd><code>{{ sasaranTerima.butir.spesimen.barcode }}</code> · {{ sasaranTerima.butir.spesimen.jenis }} · {{ LABEL_LOKASI_PENGAMBILAN[sasaranTerima.butir.spesimen.lokasi_pengambilan] }}</dd>
        </div>
      </dl>
      <div role="alert">
        <div v-if="galatTerima" class="blok-galat">
          <Pesan jenis="error">{{ pesanGalatLab(galatTerima) }}</Pesan>
        </div>
      </div>
      <template #aksi>
        <Tombol :disabled="menerima || undefined" @click="bukaTerima = false">Batal</Tombol>
        <Tombol varian="utama" :sibuk="menerima" @click="terima">Terima di lab</Tombol>
      </template>
    </Dialog>

    <Dialog
      v-model:open="bukaInput"
      :judul="sasaranInput ? `${sasaranInput.butir.hasil ? 'Input ulang hasil' : 'Input hasil'} ${sasaranInput.butir.nama}` : 'Input hasil'"
      uraian="Penanda rendah, tinggi, atau kritis dihitung server dari nilai rujukan RS. Hasil bisa diinput ulang selama belum divalidasi."
      lebar="lebar"
      :sibuk="menyimpan"
      :fokus-sesudah-tutup="fokusSesudahTutup"
    >
      <dl v-if="sasaranInput" class="ringkas">
        <div>
          <dt>Pasien</dt>
          <dd>{{ identitasPasien(sasaranInput.baris) }}</dd>
        </div>
        <div>
          <dt>Pemeriksaan</dt>
          <dd><code>{{ sasaranInput.butir.kode_item }}</code> {{ sasaranInput.butir.nama }}</dd>
        </div>
        <div v-if="sasaranInput.butir.spesimen">
          <dt>Spesimen</dt>
          <dd><code>{{ sasaranInput.butir.spesimen.barcode }}</code> · {{ sasaranInput.butir.spesimen.jenis }}</dd>
        </div>
        <div v-if="sasaranInput.butir.hasil">
          <dt>Hasil sebelumnya</dt>
          <dd class="biasa"><TampilanHasilLab :hasil="sasaranInput.butir.hasil" /></dd>
        </div>
      </dl>
      <form id="form-input-hasil" class="form" novalidate @submit.prevent="simpanInput">
        <KolomIsian v-slot="a" label="Hasil" wajib :petunjuk="petunjukInput" :error="errorNilaiInput ?? undefined">
          <input v-bind="a" v-model="nilaiInput" type="text" class="isian" maxlength="500" autocomplete="off" :inputmode="jenisInput === 'numerik' ? 'decimal' : 'text'" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Catatan" petunjuk="Opsional, paling banyak 1.000 karakter." :error="kolomInput.catatan">
          <textarea v-bind="a" v-model="catatanInput" class="isian" rows="2" maxlength="1000"></textarea>
        </KolomIsian>
      </form>
      <div role="alert">
        <div v-if="pesanInput" class="blok-galat">
          <Pesan jenis="error">{{ pesanInput }}</Pesan>
        </div>
      </div>
      <template #aksi>
        <Tombol :disabled="menyimpan || undefined" @click="bukaInput = false">Batal</Tombol>
        <Tombol type="submit" form="form-input-hasil" varian="utama" :sibuk="menyimpan">Simpan hasil</Tombol>
      </template>
    </Dialog>

    <Dialog
      v-model:open="bukaValidasi"
      :judul="sasaranValidasi ? `${otorisasi ? 'Otorisasi' : 'Validasi'} hasil ${sasaranValidasi.butir.nama}` : 'Validasi hasil'"
      :uraian="uraianValidasi"
      lebar="lebar"
      :sibuk="memvalidasi"
      :fokus-sesudah-tutup="fokusSesudahTutup"
    >
      <dl v-if="sasaranValidasi" class="ringkas">
        <div>
          <dt>Pasien</dt>
          <dd>{{ identitasPasien(sasaranValidasi.baris) }}</dd>
        </div>
        <div>
          <dt>Pemeriksaan</dt>
          <dd><code>{{ sasaranValidasi.butir.kode_item }}</code> {{ sasaranValidasi.butir.nama }}</dd>
        </div>
        <div>
          <dt>Hasil</dt>
          <dd class="biasa"><TampilanHasilLab :hasil="sasaranValidasi.hasil" /></dd>
        </div>
        <div v-if="otorisasi">
          <dt>Divalidasi analis</dt>
          <dd>{{ sasaranValidasi.hasil.divalidasi_oleh?.nama ?? 'Tidak tercatat' }}</dd>
        </div>
      </dl>
      <p v-if="memuatValidasi" class="ket-muat" role="status">Memeriksa hasil terbaru dari server…</p>
      <div role="alert">
        <div v-if="hasilBerubahPesan" class="blok-galat">
          <Pesan jenis="peringatan">{{ hasilBerubahPesan }}</Pesan>
        </div>
        <div v-if="tidakBisaValidasi" class="blok-galat">
          <Pesan jenis="error">{{ tidakBisaValidasi }}</Pesan>
        </div>
        <div v-if="galatMuatValidasi" class="blok-galat muat-gagal">
          <Pesan jenis="error">Hasil terbaru belum bisa diperiksa: {{ pesanGalatLab(galatMuatValidasi) }} Konfirmasi ditahan sampai pemeriksaan berhasil.</Pesan>
          <Tombol :sibuk="memuatValidasi" @click="cobaMuatValidasi">Coba lagi</Tombol>
        </div>
        <div v-if="galatValidasi" class="blok-galat">
          <Pesan jenis="error">{{ pesanGalatLab(galatValidasi) }}</Pesan>
        </div>
      </div>
      <template #aksi>
        <Tombol :disabled="memvalidasi || undefined" @click="bukaValidasi = false">Batal</Tombol>
        <Tombol v-if="!tidakBisaValidasi" varian="utama" :sibuk="memvalidasi" :disabled="memuatValidasi || !!galatMuatValidasi || undefined" @click="validasi">{{ otorisasi ? 'Otorisasi hasil' : 'Validasi hasil' }}</Tombol>
      </template>
    </Dialog>

    <DialogAdendumHasilLab v-model:open="bukaAdendum" :hasil="sasaranAdendum" :fokus-sesudah-tutup="fokusSesudahTutup" @selesai="adendumSelesai" @muat-ulang="segarkan" />
  </HalamanModul>
</template>

<style scoped>
.tumpukan {
  display: grid;
  gap: var(--space-4);
}
.hasil:focus-visible {
  outline: 2px solid var(--brand-600);
  outline-offset: 2px;
  border-radius: var(--radius-input);
}
/* Identitas dua penanda dibaca sebelum konfirmasi (PRD 5.1 butir 9): label kecil, isi tebal. */
.ringkas {
  display: grid;
  gap: var(--space-2);
  margin: 0 0 var(--space-3);
}
.ringkas dt {
  font-size: 12px;
  color: var(--muted);
}
.ringkas dd {
  margin: 0;
  font-weight: var(--font-weight-semibold);
}
.ringkas dd.biasa {
  font-weight: inherit;
}
.form {
  display: grid;
  gap: var(--space-3);
}
.pilihan-lokasi {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  border: 0;
}
.pilihan {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 44px;
  box-sizing: border-box;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border-input);
  border-radius: var(--radius-input);
  background: var(--surface);
  cursor: pointer;
}
.pilihan:has(input:focus-visible) {
  outline: 2px solid var(--brand-600);
  outline-offset: 2px;
}
/* Terpilih: tepi tebal dan teks tebal, bukan hanya warna. */
.pilihan.dipilih {
  border-width: 2px;
  border-color: var(--text);
  font-weight: var(--font-weight-semibold);
}
.galat {
  margin: 0;
  font-size: 13px;
  color: var(--danger);
}
.blok-galat {
  margin-top: var(--space-3);
}
.muat-gagal {
  display: grid;
  gap: var(--space-2);
  justify-items: start;
}
.ket-muat {
  margin: 0;
  font-size: 13px;
  color: var(--muted);
}
</style>
