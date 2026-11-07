<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import Tombol from '@/design-system/components/Tombol.vue'
import { jamWib } from '../antrean'
import { bacaDisplay, kunciPanggilan, lupakanToken, panggilanBaru, siapkanToken, ucapkan } from '../display'
import type { DisplayAntrean } from '../tipe'

/**
 * Display antrean untuk TV ruang tunggu (tiket 2.8, FR-02-04/15). Rute publik tanpa sesi; perangkat memakai token yang
 * dibawa sekali lewat fragmen URL (`#token=...`, lihat display.ts). Hanya nomor, inisial, dan tujuan dari server; suara
 * memakai Web Speech API bawaan peramban dengan `teks_suara` (tanpa nama), diputar untuk panggilan yang belum diputar.
 * Polling mengikuti `interval_detik` dengan If-None-Match.
 */
const area = String(useRoute().params.area ?? '')
const token = ref<string | null>(null)
const data = ref<DisplayAntrean | null>(null)
const ditolak = ref<number | null>(null)
const terputus = ref(false)
const suara = ref(false)
const suaraTakDidukung = ref(false)

let etag: string | null = null
let penunda: ReturnType<typeof setTimeout> | undefined
let berhenti = false
// Panggilan yang sudah ada saat layar dibuka tidak diumumkan ulang (mis. TV dimuat ulang).
const sudahDiputar = new Set<string>()
let pembacaanPertama = true

async function baca() {
  if (berhenti || !token.value) return
  const hasil = await bacaDisplay(area, token.value, etag)
  let jeda = 5000
  if (hasil.jenis === 'ditolak') {
    ditolak.value = hasil.status
    if (hasil.status !== 404) lupakanToken()
    return
  }
  terputus.value = hasil.jenis === 'gagal'
  if (hasil.jenis === 'gagal') jeda = 10_000
  if (hasil.jenis === 'baru') {
    etag = hasil.etag
    data.value = hasil.data
    jeda = hasil.data.interval_detik * 1000
    for (const p of panggilanBaru(sudahDiputar, hasil.data.dipanggil)) {
      sudahDiputar.add(kunciPanggilan(p))
      if (!pembacaanPertama && suara.value) ucapkan(p.teks_suara)
    }
    pembacaanPertama = false
  }
  penunda = setTimeout(() => void baca(), jeda)
}

function aktifkanSuara() {
  suaraTakDidukung.value = typeof speechSynthesis === 'undefined'
  suara.value = !suaraTakDidukung.value
}

onMounted(() => {
  document.title = 'Display antrean · Karsitara'
  token.value = siapkanToken()
  void baca()
})
onBeforeUnmount(() => {
  berhenti = true
  clearTimeout(penunda)
})

const utama = computed(() => data.value?.dipanggil[0] ?? null)
const sebelumnya = computed(() => data.value?.dipanggil.slice(1) ?? [])
const pesanDitolak = computed(() =>
  ditolak.value === 404 ? 'Area antrean tidak dikenal. Periksa alamat layar ini.' : 'Token perangkat ditolak atau sudah dicabut. Minta petugas membuat token baru, lalu buka alamat layar ini dengan #token=…',
)
</script>

<template>
  <main class="display" :aria-label="data ? `Display antrean ${data.area.nama}` : 'Display antrean'">
    <header class="kepala">
      <h1>{{ data?.area.nama ?? 'Antrean' }}</h1>
      <div class="kepala-aksi">
        <span v-if="data" class="waktu">Diperbarui {{ jamWib(data.waktu_server) }}</span>
        <Tombol v-if="!suara" :disabled="suaraTakDidukung || undefined" @click="aktifkanSuara">{{ suaraTakDidukung ? 'Suara tidak didukung peramban' : 'Aktifkan suara' }}</Tombol>
        <span v-else class="waktu">Suara aktif</span>
      </div>
    </header>

    <div v-if="!token" class="tengah" role="alert">
      <h2>Perangkat belum disiapkan</h2>
      <p>Buka alamat layar ini sekali dengan token perangkat di belakangnya (<code>#token=…</code>). Token dibuat petugas lewat <code>php artisan antrean:token-display</code>.</p>
    </div>
    <div v-else-if="ditolak" class="tengah" role="alert">
      <h2>Display tidak dapat dibuka</h2>
      <p>{{ pesanDitolak }}</p>
    </div>
    <div v-else-if="!data" class="tengah" role="status"><p>Memuat antrean…</p></div>
    <template v-else>
      <p v-if="terputus" class="putus" role="status">Koneksi ke server terputus. Menampilkan data terakhir dan mencoba lagi.</p>
      <div class="isi">
        <section class="panggilan" aria-labelledby="judul-dipanggil" aria-live="polite">
          <h2 id="judul-dipanggil">Sedang dipanggil</h2>
          <template v-if="utama">
            <p class="nomor" data-testid="nomor-utama">{{ utama.nomor_tampil }}</p>
            <p v-if="utama.inisial" class="inisial">{{ utama.inisial }}</p>
            <p class="tujuan">{{ utama.tujuan }}</p>
          </template>
          <p v-else class="kosong">Belum ada nomor yang dipanggil.</p>
          <ul v-if="sebelumnya.length" class="sebelumnya" aria-label="Panggilan sebelumnya">
            <li v-for="p in sebelumnya" :key="kunciPanggilan(p)">
              <span class="nomor-kecil">{{ p.nomor_tampil }}</span>
              <span v-if="p.inisial">{{ p.inisial }}</span>
              <span>{{ p.tujuan }}</span>
            </li>
          </ul>
        </section>
        <section class="berikutnya" aria-labelledby="judul-berikutnya">
          <h2 id="judul-berikutnya">Berikutnya</h2>
          <ul v-if="data.berikutnya.length">
            <li v-for="b in data.berikutnya" :key="b.nomor_tampil"><span class="nomor-kecil">{{ b.nomor_tampil }}</span><span v-if="b.inisial">{{ b.inisial }}</span></li>
          </ul>
          <p v-else class="kosong">Tidak ada nomor yang menunggu.</p>
        </section>
      </div>
    </template>
  </main>
</template>

<style scoped>
.display {
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
  min-height: 100vh;
  padding: var(--space-6) var(--space-8);
  background: var(--panel-merek);
  color: var(--surface);
  font-family: var(--font-ui);
}
.kepala {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
}
.kepala h1 {
  margin: 0;
  font-size: clamp(24px, 3vw, 40px);
}
.kepala-aksi {
  display: flex;
  align-items: center;
  gap: var(--space-4);
}
.waktu {
  font-size: 16px;
  color: var(--brand-100);
}
.isi {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
  gap: var(--space-8);
  align-items: start;
}
h2 {
  margin: 0 0 var(--space-3);
  font-size: clamp(18px, 2vw, 28px);
  font-weight: var(--font-weight-semibold);
  color: var(--brand-100);
}
.panggilan,
.berikutnya,
.tengah {
  min-width: 0;
  padding: var(--space-6);
  border: 1px solid var(--brand-500);
  border-radius: var(--radius-card);
}
.nomor {
  margin: 0;
  font-family: var(--font-mono);
  font-size: clamp(72px, 14vw, 220px);
  font-weight: var(--font-weight-bold);
  line-height: 1;
  color: var(--penekanan-panel);
}
.inisial {
  margin: var(--space-2) 0 0;
  font-size: clamp(28px, 4vw, 56px);
}
.tujuan {
  margin: var(--space-3) 0 0;
  font-size: clamp(24px, 3.5vw, 48px);
  font-weight: var(--font-weight-semibold);
}
.sebelumnya,
.berikutnya ul {
  list-style: none;
  margin: var(--space-6) 0 0;
  padding: 0;
  display: grid;
  gap: var(--space-3);
  font-size: clamp(18px, 2vw, 28px);
}
.berikutnya ul {
  margin-top: 0;
}
.sebelumnya li,
.berikutnya li {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-4);
  align-items: baseline;
}
.nomor-kecil {
  font-family: var(--font-mono);
  font-weight: var(--font-weight-bold);
  min-width: 5ch;
}
.kosong,
.tengah p {
  margin: 0;
  font-size: clamp(18px, 2vw, 28px);
}
.tengah {
  margin: auto;
  max-width: 60ch;
}
.putus {
  margin: 0;
  padding: var(--space-2) var(--space-4);
  border-radius: var(--radius-input);
  background: var(--warn-bg);
  color: var(--warn);
  font-size: 18px;
}
code {
  font-family: var(--font-mono);
}
@media (max-width: 900px) {
  .display {
    padding: var(--space-4);
  }
  .isi {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
