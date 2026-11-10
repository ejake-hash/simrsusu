<script setup lang="ts">
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { jamWib } from '@/modules/antrean/antrean'
import { INTERVAL_LAYAR_KERJA } from '@/modules/notifikasi/polling'
import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import { alokasikanTempatTidur, bacaPetaTempatTidur, batalkanPermintaanKamar, daftarPermintaanTempatTidur, tempatkanPasien } from '../api'
import {
  bolehAlokasi,
  bolehBatalkan,
  bolehTempatkan,
  formatLamaMenunggu,
  KUNCI_ADMISI,
  kurangAlasanBatal,
  kurangSerahTerima,
  labelAsal,
  perluMuatUlang,
  permintaanBasi,
  pesanGalatAlokasi,
  pesanGalatBatal,
  pesanGalatTempatkan,
  pilihanTtAlokasi,
  tandaPermintaan,
  teksTtDipesan,
} from '../admisi'
import { KUNCI_PETA, tandaTambahan } from '../peta'
import type { PermintaanTempatTidur, TempatTidurPeta } from '../tipe'

/**
 * Pendaftaran → Admisi rawat inap (tiket 2.28, kontrak v0.36.0; FR-08-03). Satu antrean RS untuk semua permintaan kamar
 * (SPRI poli dan disposisi IGD), terlama di atas; urutan dan lama menunggu dari server. Alokasi memilih TT `dapat_dipilih`
 * dari peta TT (sumber yang sama dengan Rawat Inap → Peta tempat tidur) lalu TT menjadi dipesan. Galat 409 memuat ulang
 * antrean dan pilihan TT dulu, baru pesan tampil (pola peta TT, PR 199). Tiket 2.29 (v0.37.0): permintaan dialokasikan bisa
 * ditempatkan (pasien tiba, TT terisi, kunjungan rawat inap dibuka) dan permintaan aktif bisa dibatalkan dengan alasan.
 */
const qc = useQueryClient()
const route = useRoute()

const status = ref<'' | 'menunggu' | 'dialokasikan'>('')
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_ADMISI.antrean(status.value)),
  queryFn: ({ pageParam }) => daftarPermintaanTempatTidur(status.value || null, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  refetchInterval: INTERVAL_LAYAR_KERJA,
  // Tanpa izin lihat: HalamanModul menampilkan Akses ditolak; tidak ada GET atau polling yang berujung 403.
  enabled: computed(() => boleh('pendaftaran.admisi.lihat')),
})
const antrean = computed<PermintaanTempatTidur[]>(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])

const izinInput = computed(() => boleh('pendaftaran.admisi.input'))
const izinPenempatan = computed(() => boleh('rawat_inap.penempatan.input'))
const izinPeta = computed(() => boleh('rawat_inap.peta_tempat_tidur.lihat'))
// Kolom sengaja ramping (teks boleh turun baris) agar tabel muat di 1024 px tanpa gulir samping.
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'pasien', label: 'Pasien' },
  { kunci: 'asal', label: 'Asal', lebar: '120px' },
  { kunci: 'kelas', label: 'Kelas diminta' },
  { kunci: 'rencana', label: 'Rencana rawat', lebar: '130px', prioritas: 2 },
  { kunci: 'lama', label: 'Lama menunggu', lebar: '140px' },
  { kunci: 'status', label: 'Status' },
  ...(izinInput.value || izinPenempatan.value ? [{ kunci: 'aksi', label: 'Aksi', lebar: '160px' }] : []),
])

// Tautan tugas menunggu kamar (?permintaan=<id>): baris itu disorot.
const disorot = computed(() => (typeof route.query.permintaan === 'string' ? route.query.permintaan : null))

// ── Alokasi TT ──
const sasaran = ref<PermintaanTempatTidur | null>(null)
const buka = ref(false)
const ttId = ref('')
const galat = ref<string | null>(null)
const menyimpan = ref(false)
const kueriPeta = useQuery({
  queryKey: [...KUNCI_PETA.semua, 'pilihan-alokasi'],
  queryFn: async () => {
    const r = await bacaPetaTempatTidur({ gedung_id: null, bangsal_id: null })
    if (!r.berubah) throw new Error('Peta tidak berubah')
    return r.data
  },
  enabled: computed(() => buka.value && izinPeta.value),
  staleTime: 0,
})
const pilihan = computed<TempatTidurPeta[]>(() => (sasaran.value ? pilihanTtAlokasi(kueriPeta.data.value?.data ?? [], sasaran.value) : []))
// Pilihan yang hilang sesudah muat ulang (TT dipesan pihak lain) tidak boleh tetap terpilih.
watch(pilihan, (p) => {
  if (ttId.value && !p.some((tt) => tt.id === ttId.value)) ttId.value = ''
})
const ttTerpilih = computed(() => pilihan.value.find((tt) => tt.id === ttId.value) ?? null)
const keteranganTt = (tt: TempatTidurPeta) => [tt.kamar.nama, tt.bangsal.nama, tt.kelas_rawat.nama, ...tandaTambahan(tt)].join(' · ')

function minta(p: PermintaanTempatTidur) {
  sasaran.value = p
  ttId.value = ''
  galat.value = null
  fokusSesudah = null
  buka.value = true
  void kueriPeta.refetch()
}

let fokusSesudah: string | null = null
async function simpan() {
  const p = sasaran.value
  const tt = ttTerpilih.value
  if (!p || !tt) return
  menyimpan.value = true
  galat.value = null
  try {
    const hasil = await alokasikanTempatTidur(p.id, tt.id)
    await Promise.all([kueri.refetch(), qc.invalidateQueries({ queryKey: KUNCI_PETA.semua })])
    fokusSesudah = `permintaan-${p.id}`
    buka.value = false
    tampilkanToast(`TT ${hasil.tempat_tidur?.kode ?? tt.kode} dipesan untuk ${p.pasien.nama}.`, 'ok')
  } catch (e) {
    const pesan = pesanGalatAlokasi(e, tt.kode)
    // Data basi: antrean dan pilihan TT dimuat ulang dulu, baru pesan tampil.
    if (perluMuatUlang(e)) await Promise.all([kueri.refetch(), kueriPeta.refetch()])
    const permintaanBasi = e instanceof ApiError && (e.kode === 'permintaan_kamar_tidak_menunggu' || e.status === 404)
    if (permintaanBasi) {
      fokusSesudah = `permintaan-${p.id}`
      buka.value = false
      tampilkanToast(pesan, 'error')
    } else {
      galat.value = pesan
    }
  } finally {
    menyimpan.value = false
  }
}
// Permintaan bisa hilang dari antrean: fokus ke judul tabel, bukan ke tombol yang sudah tiada.
const fokusSesudahTutup = () => (fokusSesudah ? (document.getElementById(fokusSesudah) ?? document.getElementById('judul-antrean-kamar')) : null)

const judulDialog = computed(() => (sasaran.value ? `Alokasikan TT untuk ${sasaran.value.pasien.nama}` : 'Alokasikan TT'))
const uraianDialog = computed(() => {
  const p = sasaran.value
  if (!p) return undefined
  const kelas = p.kelas_diminta ? `Kelas diminta ${p.kelas_diminta.nama}: hanya TT kelas itu yang tampil.` : 'Kelas belum ditentukan (disposisi IGD): TT kelas apa pun bisa dipilih.'
  return `No. RM ${formatNoRm(p.pasien.no_rm)} · ${p.kunjungan.nomor}. ${kelas}`
})

// ── Tempatkan (2.29): pasien tiba di bangsal; konfirmasi identitas dua penanda dan TT pesanan, serah terima opsional ──
const sasaranTempat = ref<PermintaanTempatTidur | null>(null)
const bukaTempat = ref(false)
const serahTerima = ref('')
const galatTempat = ref<unknown>(null)
const menempatkan = ref(false)
function mintaTempatkan(p: PermintaanTempatTidur) {
  sasaranTempat.value = p
  serahTerima.value = ''
  galatTempat.value = null
  fokusSesudah = null
  bukaTempat.value = true
}
const kurangTempat = computed(() => kurangSerahTerima(serahTerima.value))
const errorSerah = computed(() => errorKolom(galatTempat.value, ['serah_terima']).serah_terima ?? null)
const pesanTempat = computed(() => (galatTempat.value && sasaranTempat.value?.tempat_tidur ? pesanGalatTempatkan(galatTempat.value, sasaranTempat.value.tempat_tidur.kode) : null))
async function tempatkan() {
  const p = sasaranTempat.value
  if (!p?.tempat_tidur || kurangTempat.value) return
  const kodeTt = p.tempat_tidur.kode
  menempatkan.value = true
  galatTempat.value = null
  try {
    const hasil = await tempatkanPasien(p.id, serahTerima.value.trim() || null)
    await Promise.all([kueri.refetch(), qc.invalidateQueries({ queryKey: KUNCI_PETA.semua })])
    fokusSesudah = 'judul-antrean-kamar'
    bukaTempat.value = false
    const hari = hasil.hari_rawat ? `, hari rawat ke-${hasil.hari_rawat}` : ''
    tampilkanToast(`${p.pasien.nama} ditempatkan di TT ${hasil.penempatan?.tempat_tidur.kode ?? kodeTt} (${hasil.kunjungan.nomor}${hari}).`, 'ok')
  } catch (e) {
    if (perluMuatUlang(e)) await Promise.all([kueri.refetch(), qc.invalidateQueries({ queryKey: KUNCI_PETA.semua })])
    if (permintaanBasi(e)) {
      fokusSesudah = `permintaan-${p.id}`
      bukaTempat.value = false
      tampilkanToast(pesanGalatTempatkan(e, kodeTt) ?? pesanError(e), 'error')
    } else {
      galatTempat.value = e
    }
  } finally {
    menempatkan.value = false
  }
}

// ── Batalkan permintaan (2.29): alasan wajib; TT pesanan kembali kosong siap ──
const sasaranBatal = ref<PermintaanTempatTidur | null>(null)
const bukaBatal = ref(false)
const alasanBatal = ref('')
const galatBatal = ref<unknown>(null)
const membatalkan = ref(false)
function mintaBatal(p: PermintaanTempatTidur) {
  sasaranBatal.value = p
  alasanBatal.value = ''
  galatBatal.value = null
  fokusSesudah = null
  bukaBatal.value = true
}
const kurangBatal = computed(() => kurangAlasanBatal(alasanBatal.value))
const errorAlasan = computed(() => errorKolom(galatBatal.value, ['alasan']).alasan ?? null)
const pesanBatal = computed(() => (galatBatal.value ? pesanGalatBatal(galatBatal.value) : null))
async function batalkan() {
  const p = sasaranBatal.value
  if (!p || kurangBatal.value) return
  membatalkan.value = true
  galatBatal.value = null
  try {
    await batalkanPermintaanKamar(p.id, alasanBatal.value.trim())
    await Promise.all([kueri.refetch(), qc.invalidateQueries({ queryKey: KUNCI_PETA.semua })])
    fokusSesudah = 'judul-antrean-kamar'
    bukaBatal.value = false
    const tt = p.tempat_tidur ? ` TT ${p.tempat_tidur.kode} kembali kosong siap.` : ''
    tampilkanToast(`Permintaan kamar ${p.pasien.nama} dibatalkan.${tt}`, 'ok')
  } catch (e) {
    if (perluMuatUlang(e)) await kueri.refetch()
    if (permintaanBasi(e)) {
      fokusSesudah = `permintaan-${p.id}`
      bukaBatal.value = false
      tampilkanToast(pesanGalatBatal(e) ?? pesanError(e), 'error')
    } else {
      galatBatal.value = e
    }
  } finally {
    membatalkan.value = false
  }
}
const identitas = (p: PermintaanTempatTidur) => `${p.pasien.nama}, No. RM ${formatNoRm(p.pasien.no_rm)}, lahir ${formatTanggal(p.pasien.tanggal_lahir)}`
</script>

<template>
  <HalamanModul
    izin="pendaftaran.admisi.lihat"
    judul="Admisi rawat inap"
    :uraian="`Antrean permintaan kamar dari SPRI poliklinik dan disposisi IGD, terlama menunggu di atas. Diperbarui otomatis tiap ${INTERVAL_LAYAR_KERJA / 1000} detik.`"
  >
    <section class="kartu" aria-labelledby="judul-antrean-kamar">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-antrean-kamar" tabindex="-1">Antrean permintaan kamar</h2>
          <p>Lama menunggu dihitung sejak SPRI, atau sejak awal tanggal rencana bila rawat direncanakan nanti.</p>
        </div>
        <div class="filter">
          <label for="filter-status-permintaan">Status</label>
          <select id="filter-status-permintaan" v-model="status" class="isian">
            <option value="">Semua yang aktif</option>
            <option value="menunggu">Menunggu TT</option>
            <option value="dialokasikan">TT dipesan</option>
          </select>
        </div>
      </div>

      <DataTable
        :kolom="kolom"
        :baris="antrean"
        :kunci-baris="(p: PermintaanTempatTidur) => p.id"
        label="Tabel antrean permintaan kamar"
        :terpilih="disorot"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        :teks-kosong="status ? 'Tidak ada permintaan kamar dengan status ini.' : 'Belum ada permintaan kamar. Permintaan muncul sesudah dokter poli membuat SPRI atau dokter IGD memberi disposisi rawat inap.'"
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-pasien="{ baris: p }">
          <b :id="`permintaan-${p.id}`" tabindex="-1" class="nama">{{ p.pasien.nama }}</b>
          <span class="ket">No. RM {{ formatNoRm(p.pasien.no_rm) }} · {{ formatTanggal(p.pasien.tanggal_lahir) }}</span>
          <code class="ket">{{ p.kunjungan.nomor }}</code>
        </template>
        <template #sel-asal="{ baris: p }">
          {{ labelAsal(p.asal) }}
          <span class="ket angka">SPRI {{ jamWib(p.diminta_pada) }}</span>
        </template>
        <template #sel-kelas="{ baris: p }">
          <span v-if="p.kelas_diminta">{{ p.kelas_diminta.nama }}</span>
          <span v-else class="ket-kuat">Belum ditentukan</span>
          <span v-if="p.bangsal_pilihan" class="ket">Bangsal pilihan: {{ p.bangsal_pilihan.nama }}</span>
        </template>
        <template #sel-rencana="{ baris: p }"><span class="angka">{{ formatTanggal(p.spri.tanggal_rencana) }}</span></template>
        <template #sel-lama="{ baris: p }"><span class="angka">{{ formatLamaMenunggu(p.lama_menunggu_menit) }}</span></template>
        <template #sel-status="{ baris: p }">
          <Lencana :jenis="tandaPermintaan(p).jenis" tanpa-ikon class="lencana"><Ikon :nama="tandaPermintaan(p).ikon" :ukuran="12" />{{ tandaPermintaan(p).label }}</Lencana>
          <span v-if="p.tempat_tidur" class="ket-kuat">{{ teksTtDipesan(p.tempat_tidur) }}</span>
        </template>
        <template #sel-aksi="{ baris: p }">
          <div v-if="bolehAlokasi(p, izinInput) || bolehTempatkan(p, izinPenempatan) || bolehBatalkan(p, izinInput)" class="aksi-baris">
            <Tombol v-if="bolehAlokasi(p, izinInput)" varian="utama" :aria-label="`Alokasikan TT untuk ${p.pasien.nama} ${p.kunjungan.nomor}`" @click="minta(p)">Alokasikan TT</Tombol>
            <Tombol v-if="bolehTempatkan(p, izinPenempatan)" varian="utama" :aria-label="`Tempatkan ${p.pasien.nama} ${p.kunjungan.nomor}`" @click="mintaTempatkan(p)">Tempatkan</Tombol>
            <Tombol v-if="bolehBatalkan(p, izinInput)" :aria-label="`Batalkan permintaan kamar ${p.pasien.nama} ${p.kunjungan.nomor}`" @click="mintaBatal(p)">Batalkan</Tombol>
          </div>
          <span v-else class="ket">Tidak ada aksi</span>
        </template>
      </DataTable>
    </section>

    <Dialog v-model:open="buka" :judul="judulDialog" :uraian="uraianDialog" lebar="lebar" :sibuk="menyimpan" :fokus-sesudah-tutup="fokusSesudahTutup">
      <Pesan v-if="!izinPeta" jenis="peringatan">Butuh izin melihat Peta tempat tidur untuk memilih TT; minta lewat Pengaturan peran.</Pesan>
      <p v-else-if="kueriPeta.isPending.value" class="tafsir" role="status">Memuat TT kosong siap…</p>
      <Pesan v-else-if="kueriPeta.isError.value" jenis="error">Pilihan TT tidak dapat dimuat: {{ pesanError(kueriPeta.error.value) }}</Pesan>
      <Pesan v-else-if="!pilihan.length" jenis="info">
        {{ sasaran?.kelas_diminta ? `Belum ada TT kosong siap untuk ${sasaran.kelas_diminta.nama} di cakupan Anda.` : 'Belum ada TT kosong siap di cakupan Anda.' }}
        TT yang dibersihkan bisa ditandai kosong siap di Rawat Inap → Peta tempat tidur.
      </Pesan>
      <div v-if="izinPeta && kueriPeta.data.value?.meta.terpotong" class="blok-peringatan">
        <Pesan jenis="peringatan">Peta memuat 1.000 TT pertama saja, jadi daftar TT kosong siap di bawah bisa tidak lengkap.</Pesan>
      </div>
      <fieldset v-if="izinPeta && !kueriPeta.isPending.value && !kueriPeta.isError.value && pilihan.length" class="pilihan-tt">
        <legend class="kolom-label">TT kosong siap <span class="kolom-wajib" aria-hidden="true">*</span></legend>
        <p v-if="sasaran?.bangsal_pilihan" class="tafsir">Bangsal pilihan dokter: {{ sasaran.bangsal_pilihan.nama }}.</p>
        <label v-for="tt in pilihan" :key="tt.id" class="pilihan" :class="{ dipilih: ttId === tt.id }">
          <input v-model="ttId" type="radio" name="tt-alokasi" :value="tt.id" />
          <span>
            <span class="kode">{{ tt.kode }}</span>
            <span class="ket">{{ keteranganTt(tt) }}</span>
          </span>
        </label>
      </fieldset>
      <div v-if="galat" class="blok-galat">
        <Pesan jenis="error">{{ galat }}</Pesan>
      </div>
      <template #aksi>
        <Tombol :disabled="menyimpan || undefined" @click="buka = false">Batal</Tombol>
        <Tombol varian="utama" :disabled="!ttTerpilih || undefined" :sibuk="menyimpan" @click="simpan">{{ ttTerpilih ? `Alokasikan ${ttTerpilih.kode}` : 'Alokasikan TT' }}</Tombol>
      </template>
    </Dialog>

    <Dialog
      v-model:open="bukaTempat"
      :judul="sasaranTempat ? `Tempatkan ${sasaranTempat.pasien.nama}?` : 'Tempatkan pasien?'"
      uraian="Cocokkan identitas pasien yang tiba di bangsal dengan data ini sebelum menempatkan. Jam penempatan dicatat server."
      lebar="lebar"
      :sibuk="menempatkan"
      :fokus-sesudah-tutup="fokusSesudahTutup"
    >
      <dl v-if="sasaranTempat" class="ringkas-tempat">
        <div>
          <dt>Pasien</dt>
          <dd>{{ identitas(sasaranTempat) }}</dd>
        </div>
        <div>
          <dt>Kunjungan asal</dt>
          <dd>{{ labelAsal(sasaranTempat.asal) }} · <code>{{ sasaranTempat.kunjungan.nomor }}</code></dd>
        </div>
        <div v-if="sasaranTempat.tempat_tidur">
          <dt>TT tujuan</dt>
          <dd class="kode-tujuan">{{ teksTtDipesan(sasaranTempat.tempat_tidur) }}</dd>
        </div>
      </dl>
      <KolomIsian v-slot="a" label="Serah terima saat tiba (opsional)" petunjuk="Bagian RM-15: kondisi pasien, terapi berjalan, hal yang perlu diawasi. Bila diisi, 5 sampai 4.000 karakter." :error="errorSerah ?? kurangTempat ?? undefined">
        <textarea v-bind="a" v-model="serahTerima" class="isian" rows="3" maxlength="4000"></textarea>
      </KolomIsian>
      <div v-if="pesanTempat" class="blok-galat">
        <Pesan jenis="error">{{ pesanTempat }}</Pesan>
      </div>
      <template #aksi>
        <Tombol :disabled="menempatkan || undefined" @click="bukaTempat = false">Batal</Tombol>
        <Tombol varian="utama" :disabled="kurangTempat !== null || undefined" :sibuk="menempatkan" @click="tempatkan">{{ sasaranTempat?.tempat_tidur ? `Tempatkan di ${sasaranTempat.tempat_tidur.kode}` : 'Tempatkan' }}</Tombol>
      </template>
    </Dialog>

    <Dialog
      v-model:open="bukaBatal"
      :judul="sasaranBatal ? `Batalkan permintaan kamar ${sasaranBatal.pasien.nama}?` : 'Batalkan permintaan kamar?'"
      :uraian="sasaranBatal ? `${identitas(sasaranBatal)} · ${sasaranBatal.kunjungan.nomor}. ${sasaranBatal.tempat_tidur ? `TT ${sasaranBatal.tempat_tidur.kode} yang dipesan kembali kosong siap.` : 'Belum ada TT yang dipesan.'} Permintaan keluar dari antrean.` : undefined"
      :sibuk="membatalkan"
      :fokus-sesudah-tutup="fokusSesudahTutup"
    >
      <KolomIsian v-slot="a" label="Alasan pembatalan" wajib petunjuk="5 sampai 1.000 karakter, tersimpan bersama pembatalan." :error="errorAlasan ?? undefined">
        <textarea v-bind="a" v-model="alasanBatal" class="isian" rows="3" maxlength="1000"></textarea>
      </KolomIsian>
      <div v-if="pesanBatal" class="blok-galat">
        <Pesan jenis="error">{{ pesanBatal }}</Pesan>
      </div>
      <template #aksi>
        <Tombol :disabled="membatalkan || undefined" @click="bukaBatal = false">Kembali</Tombol>
        <Tombol varian="bahaya" :disabled="kurangBatal !== null || undefined" :sibuk="membatalkan" @click="batalkan">Batalkan permintaan</Tombol>
      </template>
    </Dialog>
  </HalamanModul>
</template>

<style scoped>
.filter {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}
.filter label {
  font-size: 13px;
  color: var(--muted);
}
.filter select {
  min-width: 180px;
}
.nama:focus-visible,
#judul-antrean-kamar:focus-visible {
  outline: 2px solid var(--brand-600);
  outline-offset: 2px;
  border-radius: 4px;
}
.ket {
  display: block;
  font-size: 12px;
  color: var(--muted);
}
.ket-kuat {
  display: block;
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
}
.angka {
  font-variant-numeric: tabular-nums;
}
.lencana {
  white-space: normal;
}
.tafsir {
  margin: 0;
  font-size: 13px;
  color: var(--muted);
}
.pilihan-tt {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  border: 0;
}
.pilihan {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  min-height: 44px;
  box-sizing: border-box;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border-input);
  border-radius: var(--radius-input);
  background: var(--surface);
  cursor: pointer;
}
.pilihan input {
  margin-top: 3px;
}
.pilihan:has(input:focus-visible) {
  outline: 2px solid var(--brand-600);
  outline-offset: 2px;
}
/* Terpilih: tepi tebal dan teks tebal, bukan hanya warna. */
.pilihan.dipilih {
  border-width: 2px;
  border-color: var(--text);
}
.pilihan.dipilih .kode {
  font-weight: var(--font-weight-bold);
}
.kode {
  display: block;
  font-family: var(--font-mono);
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
}
.blok-peringatan {
  margin-bottom: var(--space-3);
}
.aksi-baris {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
/* Identitas dua penanda dan TT tujuan dibaca sebelum menempatkan (PRD 5.1 butir 9): label kecil, isi tebal. */
.ringkas-tempat {
  display: grid;
  gap: var(--space-2);
  margin: 0 0 var(--space-3);
}
.ringkas-tempat dt {
  font-size: 12px;
  color: var(--muted);
}
.ringkas-tempat dd {
  margin: 0;
  font-weight: var(--font-weight-semibold);
}
.kode-tujuan {
  font-family: var(--font-mono);
}
.blok-galat {
  margin-top: var(--space-3);
}
@media (max-width: 1023.98px) {
  .filter {
    width: 100%;
  }
  .filter select {
    flex: 1;
    min-width: 0;
  }
}
</style>
