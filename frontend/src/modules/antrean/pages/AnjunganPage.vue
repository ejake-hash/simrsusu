<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ambilNomorLoket,
  bacaAnjungan,
  type Hasil,
  jadwalAnjungan,
  kelompokPerPoli,
  LABEL_ALASAN_PRIORITAS,
  LABEL_STATUS_JADWAL,
  lupakanTokenAnjungan,
  pesanAnjungan,
  pilihanTanggal,
  siapkanTokenAnjungan,
} from '../anjungan'
import { jamWib } from '../antrean'
import type { AlasanPrioritas, AnjunganPerangkat, JadwalAnjungan, TiketLoket } from '../tipe'

/**
 * Anjungan pendaftaran mandiri tahap 1 (tiket 2.20; PRD 7.01.1, FR-01-23/26/28/30, FR-02-01/07). Layar kios tanpa akun:
 * ambil nomor loket (biasa atau prioritas) dan lihat jadwal dokter. Rute publik tanpa sesi; token perangkat lewat fragmen
 * URL (anjungan.ts). Tanpa data pasien. Sesi layar berakhir sendiri sesudah `batas_diam_detik` tanpa sentuhan: kembali ke
 * layar awal dan semua isian dibuang. Tombol ≥ 64 px dan teks ≥ 20 px untuk layar sentuh.
 */
type Layar = 'awal' | 'jenis' | 'alasan' | 'struk' | 'jadwal'

const token = ref<string | null>(null)
const perangkat = ref<AnjunganPerangkat | null>(null)
const ditolak = ref(false)
const galatAwal = ref<string | null>(null)

const layar = ref<Layar>('awal')
const sibuk = ref(false)
const galat = ref<string | null>(null)
const tiket = ref<TiketLoket | null>(null)
const tanggal = ref('')
const jadwal = ref<JadwalAnjungan[] | null>(null)
const judul = ref<HTMLElement | null>(null)

const tanggalPilihan = ref(pilihanTanggal(new Date()))
const kelompok = computed(() => kelompokPerPoli(jadwal.value ?? []))
const batasDiam = computed(() => perangkat.value?.batas_diam_detik ?? 60)

let penundaDiam: ReturnType<typeof setTimeout> | undefined
let penundaOnline: ReturnType<typeof setInterval> | undefined
// Nomor sesi layar: naik setiap kembali ke awal. Respons yang tiba sesudah sesi berganti (permintaan lambat, batas diam
// habis, atau Kembali ditekan) dibuang, supaya nomor atau jadwal pasien sebelumnya tidak tampil ke pasien berikutnya.
let sesi = 0
// Permintaan jadwal terakhir: hanya jawaban dari tanggal yang terakhir dipilih yang ditampilkan.
let mintaJadwalKe = 0

function tolak(h: Exclude<Hasil<unknown>, { jenis: 'ok' }>): string {
  if (h.jenis === 'ditolak') {
    ditolak.value = true
    lupakanTokenAnjungan()
  }
  return pesanAnjungan(h)
}

/** Konfigurasi + tanda online (FR-01-30): saat kembali ke layar awal dan tiap 60 detik selama diam di layar awal. */
async function laporOnline() {
  if (!token.value || ditolak.value) return
  const h = await bacaAnjungan(token.value)
  if (h.jenis === 'ok') {
    perangkat.value = h.data
    galatAwal.value = null
  } else if (h.jenis === 'ditolak' || !perangkat.value || (h.jenis === 'galat' && h.status === 409)) {
    galatAwal.value = tolak(h)
  }
  // Selain itu (denyut gagal sesaat: jaringan, 429) layar sambutan tetap bersih; denyut berikutnya mencoba lagi.
}

async function pindah(ke: Layar) {
  layar.value = ke
  galat.value = null
  sentuh()
  await nextTick()
  judul.value?.focus()
}

/** Kembali ke layar awal dan buang semua isian (server tidak menyimpan isian sesi). */
function keAwal() {
  sesi++
  sibuk.value = false
  tiket.value = null
  jadwal.value = null
  tanggal.value = ''
  void pindah('awal')
  void laporOnline()
}

/** Setiap sentuhan atau tombol menunda batas diam; di layar awal tidak ada isian yang perlu dibuang. */
function sentuh() {
  clearTimeout(penundaDiam)
  if (layar.value !== 'awal') penundaDiam = setTimeout(keAwal, batasDiam.value * 1000)
}

async function ambil(alasan: AlasanPrioritas | null) {
  if (!token.value || sibuk.value) return
  const milik = sesi
  sibuk.value = true
  const h = await ambilNomorLoket(token.value, alasan)
  if (milik !== sesi) return
  sibuk.value = false
  if (h.jenis !== 'ok') {
    galat.value = tolak(h)
    return
  }
  tiket.value = h.data
  await pindah('struk')
}

async function bukaJadwal(t: string) {
  if (!token.value) return
  const milik = sesi
  const ke = ++mintaJadwalKe
  tanggal.value = t
  sibuk.value = true
  galat.value = null
  const h = await jadwalAnjungan(token.value, t)
  if (milik !== sesi || ke !== mintaJadwalKe) return
  sibuk.value = false
  if (h.jenis !== 'ok') {
    jadwal.value = null
    galat.value = tolak(h)
    return
  }
  jadwal.value = h.data
}

async function mulaiJadwal() {
  // Dihitung ulang tiap dibuka: kios yang menyala melewati tengah malam tidak menawarkan "hari ini" yang sudah lewat.
  tanggalPilihan.value = pilihanTanggal(new Date())
  await pindah('jadwal')
  await bukaJadwal(tanggalPilihan.value[0]!.nilai)
}

const tersedia = (l: 'nomor_loket' | 'jadwal_praktik') => perangkat.value?.layanan.includes(l) ?? false

onMounted(() => {
  document.title = 'Anjungan · Karsitara'
  token.value = siapkanTokenAnjungan()
  void laporOnline()
  penundaOnline = setInterval(() => {
    if (layar.value === 'awal') void laporOnline()
  }, 60_000)
})
onBeforeUnmount(() => {
  clearTimeout(penundaDiam)
  clearInterval(penundaOnline)
})
</script>

<template>
  <main class="anjungan" :aria-label="perangkat ? `Anjungan ${perangkat.lokasi}` : 'Anjungan'" @pointerdown="sentuh" @keydown="sentuh">
    <header class="kepala">
      <p class="merek">Anjungan mandiri</p>
      <p v-if="perangkat" class="lokasi">{{ perangkat.lokasi }}</p>
    </header>

    <div v-if="!token" class="tengah" role="alert">
      <h1>Anjungan belum disiapkan</h1>
      <p>Silakan minta bantuan petugas atau langsung ke loket pendaftaran.</p>
    </div>
    <div v-else-if="ditolak" class="tengah" role="alert">
      <h1>Anjungan tidak dapat dipakai</h1>
      <p>Silakan minta bantuan petugas atau langsung ke loket pendaftaran.</p>
    </div>
    <div v-else-if="!perangkat" class="tengah" role="status">
      <p>{{ galatAwal ?? 'Menyiapkan anjungan…' }}</p>
      <button v-if="galatAwal" type="button" class="tombol-kios" @click="laporOnline">Coba lagi</button>
    </div>

    <section v-else-if="layar === 'awal'" class="isi" aria-labelledby="judul-layar">
      <h1 id="judul-layar" ref="judul" tabindex="-1">Selamat datang. Silakan pilih layanan.</h1>
      <div class="pilihan">
        <button v-if="tersedia('nomor_loket')" type="button" class="tombol-kios utama" @click="pindah('jenis')">
          Ambil nomor antrean pendaftaran
          <span class="sub">Untuk mendaftar di loket</span>
        </button>
        <button v-if="tersedia('jadwal_praktik')" type="button" class="tombol-kios" @click="mulaiJadwal">
          Lihat jadwal dokter
          <span class="sub">Hari ini sampai 6 hari ke depan</span>
        </button>
      </div>
      <p v-if="galatAwal" class="galat" role="alert">{{ galatAwal }}</p>
    </section>

    <section v-else-if="layar === 'jenis'" class="isi" aria-labelledby="judul-layar">
      <h1 id="judul-layar" ref="judul" tabindex="-1">Pilih jenis antrean</h1>
      <div class="pilihan">
        <button type="button" class="tombol-kios utama" :disabled="sibuk" :aria-busy="sibuk || undefined" @click="ambil(null)">
          Antrean biasa
        </button>
        <button type="button" class="tombol-kios" :disabled="sibuk" @click="pindah('alasan')">
          Antrean prioritas
          <span class="sub">Lansia, disabilitas, ibu hamil, anak dengan kondisi khusus</span>
        </button>
      </div>
      <p v-if="galat" class="galat" role="alert">{{ galat }}</p>
      <div class="bawah"><button type="button" class="tombol-kios kedua" @click="keAwal">Kembali</button></div>
    </section>

    <section v-else-if="layar === 'alasan'" class="isi" aria-labelledby="judul-layar">
      <h1 id="judul-layar" ref="judul" tabindex="-1">Pilih alasan antrean prioritas</h1>
      <p class="ket">Petugas loket akan memastikan kebutuhan Anda.</p>
      <div class="pilihan empat">
        <button v-for="(label, kunci) in LABEL_ALASAN_PRIORITAS" :key="kunci" type="button" class="tombol-kios" :disabled="sibuk" @click="ambil(kunci)">{{ label }}</button>
      </div>
      <p v-if="galat" class="galat" role="alert">{{ galat }}</p>
      <div class="bawah"><button type="button" class="tombol-kios kedua" :disabled="sibuk" @click="pindah('jenis')">Kembali</button></div>
    </section>

    <section v-else-if="layar === 'struk' && tiket" class="isi" aria-labelledby="judul-layar">
      <h1 id="judul-layar" ref="judul" tabindex="-1">Nomor antrean Anda<span class="tersembunyi">: {{ tiket.nomor_tampil }}</span></h1>
      <div class="struk" data-testid="struk-loket">
        <p class="nomor" data-testid="nomor-loket">{{ tiket.nomor_tampil }}</p>
        <p v-if="tiket.prioritas" class="label-prioritas">Antrean prioritas</p>
        <p>{{ tiket.menunggu_di_depan === 0 ? 'Tidak ada nomor yang menunggu di depan Anda.' : `${tiket.menunggu_di_depan} nomor menunggu di depan Anda.` }}</p>
        <p>Silakan menuju <b>{{ tiket.lokasi }}</b> dan tunggu nomor Anda dipanggil.</p>
        <p class="kecil">Diambil {{ jamWib(tiket.diambil_pada) }}. Catat atau ingat nomor ini.</p>
      </div>
      <div class="bawah"><button type="button" class="tombol-kios utama" @click="keAwal">Selesai</button></div>
    </section>

    <section v-else-if="layar === 'jadwal'" class="isi" aria-labelledby="judul-layar">
      <h1 id="judul-layar" ref="judul" tabindex="-1">Jadwal dokter</h1>
      <div class="tanggal" role="group" aria-label="Pilih tanggal">
        <button
          v-for="t in tanggalPilihan"
          :key="t.nilai"
          type="button"
          class="tombol-kios tanggal-item"
          :aria-pressed="t.nilai === tanggal"
          :disabled="sibuk"
          @click="bukaJadwal(t.nilai)"
        >
          {{ t.label }}
        </button>
      </div>
      <p v-if="galat" class="galat" role="alert">{{ galat }}</p>
      <p v-else-if="sibuk" role="status">Memuat jadwal…</p>
      <p v-else-if="jadwal && !jadwal.length" role="status">Tidak ada jadwal dokter pada tanggal ini.</p>
      <div v-else-if="jadwal" class="daftar-jadwal" aria-live="polite">
        <section v-for="k in kelompok" :key="k.poli.id" class="poli" :aria-label="k.poli.nama">
          <h2>{{ k.poli.nama }}</h2>
          <ul>
            <li v-for="(j, i) in k.jadwal" :key="`${j.dokter}-${j.jam_mulai}-${i}`">
              <span class="dokter">{{ j.dokter }}</span>
              <span class="jam">{{ j.jam_mulai.replace(':', '.') }}–{{ j.jam_selesai.replace(':', '.') }}</span>
              <span class="status" :class="`status-${j.status}`">{{ LABEL_STATUS_JADWAL[j.status] }}</span>
            </li>
          </ul>
        </section>
      </div>
      <div class="bawah"><button type="button" class="tombol-kios kedua" @click="keAwal">Kembali ke awal</button></div>
    </section>

    <footer v-if="perangkat && layar !== 'awal'" class="kaki">Layar kembali ke awal jika tidak disentuh selama {{ batasDiam }} detik.</footer>
  </main>
</template>

<style scoped>
.anjungan {
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
  min-height: 100vh;
  padding: var(--space-6) var(--space-8);
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-ui);
  font-size: 22px;
  line-height: 1.4;
}
.kepala {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: var(--space-4);
  padding: var(--space-4) var(--space-6);
  border-radius: var(--radius-card);
  background: var(--panel-merek);
  color: var(--surface);
}
.kepala p {
  margin: 0;
  font-size: 24px;
  font-weight: var(--font-weight-semibold);
}
.isi,
.tengah {
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
  flex: 1;
}
.tengah {
  max-width: 60ch;
  margin: auto;
  padding: var(--space-8);
  border-radius: var(--radius-card);
  background: var(--surface);
}
h1 {
  margin: 0;
  font-size: 36px;
  line-height: 1.2;
}
h1:focus {
  outline: none;
}
h2 {
  margin: 0 0 var(--space-3);
  font-size: 26px;
}
p {
  margin: 0;
}
.ket,
.kaki,
.kecil {
  color: var(--muted);
}
.kecil,
.kaki {
  font-size: 20px;
}
.pilihan {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: var(--space-6);
}
.pilihan.empat {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
.tombol-kios {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: var(--space-2);
  min-height: 64px;
  padding: var(--space-6);
  border: 2px solid var(--brand-600);
  border-radius: var(--radius-card);
  background: var(--surface);
  color: var(--brand-800);
  font: inherit;
  font-size: 26px;
  font-weight: var(--font-weight-semibold);
  text-align: left;
  cursor: pointer;
  touch-action: manipulation;
}
.pilihan .tombol-kios {
  min-height: 160px;
}
.tombol-kios.utama {
  background: var(--brand-600);
  color: var(--surface);
}
.tombol-kios.kedua {
  min-width: 240px;
  border-color: var(--border-input);
  color: var(--text);
  text-align: center;
  align-items: center;
}
.tombol-kios:focus-visible {
  outline: 3px solid var(--brand-600);
  outline-offset: 3px;
}
/* Untuk pembaca layar saja: nomor ikut terbaca saat fokus pindah ke judul struk. */
.tersembunyi {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}
.tombol-kios:disabled {
  cursor: progress;
  opacity: 0.6;
}
.sub {
  font-size: 20px;
  font-weight: var(--font-weight-regular);
}
.bawah {
  display: flex;
  margin-top: auto;
}
.galat {
  padding: var(--space-4);
  border-radius: var(--radius-input);
  background: var(--danger-bg);
  color: var(--danger);
}
.struk {
  display: grid;
  gap: var(--space-3);
  padding: var(--space-8);
  border: 2px solid var(--brand-600);
  border-radius: var(--radius-card);
  background: var(--surface);
  text-align: center;
}
.nomor {
  font-family: var(--font-mono);
  font-size: 120px;
  font-weight: var(--font-weight-bold);
  line-height: 1;
  color: var(--brand-800);
}
.label-prioritas {
  justify-self: center;
  padding: var(--space-1) var(--space-4);
  border-radius: var(--radius-input);
  background: var(--accent-bg);
  color: var(--accent-ink);
  font-weight: var(--font-weight-semibold);
}
.tanggal {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: var(--space-3);
}
.tanggal-item {
  padding: var(--space-3) var(--space-4);
  font-size: 20px;
  text-align: center;
  align-items: center;
}
.tanggal-item[aria-pressed='true'] {
  background: var(--brand-600);
  color: var(--surface);
}
.daftar-jadwal {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
  gap: var(--space-4);
}
.poli {
  padding: var(--space-4) var(--space-6);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-card);
  background: var(--surface);
}
.poli ul {
  display: grid;
  gap: var(--space-3);
  margin: 0;
  padding: 0;
  list-style: none;
}
.poli li {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: var(--space-2) var(--space-4);
}
.dokter {
  flex: 1 1 14ch;
  font-weight: var(--font-weight-semibold);
}
.jam {
  font-family: var(--font-mono);
}
.status {
  padding: 0 var(--space-3);
  border-radius: var(--radius-input);
  font-size: 20px;
}
.status-tersedia {
  background: var(--success-bg);
  color: var(--success);
}
.status-penuh {
  background: var(--warn-bg);
  color: var(--warn);
}
.status-tidak_praktik {
  background: var(--bg);
  color: var(--muted);
}
@media (max-width: 900px) {
  .anjungan {
    padding: var(--space-4);
  }
  .pilihan.empat,
  .daftar-jadwal {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
