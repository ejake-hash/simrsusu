<script setup lang="ts">
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '../components/DataTable.vue'
import Dialog from '../components/Dialog.vue'
import FormulirBertahap from '../components/FormulirBertahap.vue'
import KeadaanKosong from '../components/KeadaanKosong.vue'
import KpiCard from '../components/KpiCard.vue'
import Lencana from '../components/Lencana.vue'
import NotifikasiToast from '../components/NotifikasiToast.vue'
import Pesan from '../components/Pesan.vue'
import PohonData from '../components/PohonData.vue'
import PopoverPencarian, { type ItemPencarian } from '../components/PopoverPencarian.vue'
import Sakelar from '../components/Sakelar.vue'
import Tabs from '../components/Tabs.vue'
import Tombol from '../components/Tombol.vue'
import { tampilkanToast } from '../components/toast'
import IsianSandi from '../form/IsianSandi.vue'
import KolomIsian from '../form/KolomIsian.vue'
import type { SimpulPohon } from '../components/pohon'

/**
 * Katalog komponen design system (tiket 1.11), HANYA mode pengembangan: rute /katalog didaftarkan di balik
 * import.meta.env.DEV sehingga halaman dan contoh datanya tidak ikut build produksi. Semua data di sini contoh
 * tampilan (bukan data pasien, bukan angka yang tampil di layar kerja). Diuji axe di e2e/katalog.spec.ts.
 */
document.title = 'Katalog komponen · Karsitara'

// Tombol
const sibuk = ref(false)
function cobaSibuk() {
  sibuk.value = true
  setTimeout(() => (sibuk.value = false), 1500)
}

// Formulir
const nama = ref('')
const sandi = ref('')

// Data tabel contoh (sintetis)
interface Baris {
  id: string
  kode: string
  nama: string
  jenis: string
  jumlah: number
}
const kolom: KolomTabel[] = [
  { kunci: 'kode', label: 'Kode', lebar: '120px' },
  { kunci: 'nama', label: 'Nama' },
  { kunci: 'jenis', label: 'Jenis', prioritas: 2 },
  { kunci: 'jumlah', label: 'Jumlah', prioritas: 3, rata: 'kanan' },
]
const buatBaris = (n: number): Baris[] =>
  Array.from({ length: n }, (_, i) => ({ id: `b${i}`, kode: `CTH-${String(i + 1).padStart(4, '0')}`, nama: `Contoh baris ${i + 1}`, jenis: i % 2 ? 'Ruang' : 'Lantai', jumlah: (i * 7) % 40 }))
const halaman = ref(buatBaris(5))
const memuatLagi = ref(false)
function muatLagi() {
  memuatLagi.value = true
  setTimeout(() => {
    halaman.value = buatBaris(halaman.value.length + 5)
    memuatLagi.value = false
  }, 600)
}
const barisVirtual = buatBaris(2000)
const terpilih = ref<string | null>(null)

// Pohon contoh
const pohon: SimpulPohon[] = [
  {
    id: 'g1',
    label: 'Gedung A',
    keterangan: 'GA',
    anak: [
      { id: 'g1l1', label: 'Lantai 1', keterangan: 'GA-L1', anak: [{ id: 'g1l1r1', label: 'Ruang 101', keterangan: 'GA-L1-101' }, { id: 'g1l1r2', label: 'Ruang 102', keterangan: 'GA-L1-102' }] },
      { id: 'g1l2', label: 'Lantai 2', keterangan: 'GA-L2' },
    ],
  },
  { id: 'g2', label: 'Gedung B', keterangan: 'GB', anak: [{ id: 'g2l1', label: 'Lantai 1', keterangan: 'GB-L1' }] },
]
const simpulTerpilih = ref<string | null>(null)
const simpulTerbuka = ref<string[]>(['g1'])

// Formulir bertahap
const langkah = [
  { kode: 'identitas', label: 'Identitas' },
  { kode: 'lokasi', label: 'Lokasi' },
  { kode: 'tinjau', label: 'Tinjau' },
]
const langkahAktif = ref('identitas')

// Pencarian
const bukaCari = ref(false)
const kueriCari = ref('')
const semuaItem: ItemPencarian[] = ['Peran & izin', 'Akun & akses', 'Jejak audit', 'Profil rumah sakit'].map((label, i) => ({ kode: `k${i}`, label, keterangan: 'Pengaturan Sistem' }))
const itemCari = computed(() => (kueriCari.value.trim() ? semuaItem.filter((i) => i.label.toLowerCase().includes(kueriCari.value.toLowerCase())) : []))
const statusCari = computed(() => (!kueriCari.value.trim() ? 'Ketik untuk mencari.' : itemCari.value.length ? `${itemCari.value.length} cocok.` : 'Tidak ada yang cocok.'))

// Lain-lain
const dialog = ref(false)
const tab = ref('satu')
const sakelar = ref(true)
const DAFTAR = [
  { id: 'tombol', label: 'Tombol' },
  { id: 'pesan', label: 'Pesan dan lencana' },
  { id: 'formulir', label: 'Formulir' },
  { id: 'keadaan', label: 'Keadaan kosong' },
  { id: 'kpi', label: 'Kartu angka' },
  { id: 'tabel', label: 'Tabel data' },
  { id: 'pohon', label: 'Pohon data' },
  { id: 'bertahap', label: 'Formulir bertahap' },
  { id: 'toast', label: 'Notifikasi toast' },
  { id: 'cari', label: 'Pencarian' },
  { id: 'lain', label: 'Dialog, tab, sakelar' },
]
</script>

<template>
  <div class="katalog">
    <header class="kepala">
      <h1>Katalog komponen</h1>
      <p>Hanya mode pengembangan. Data di halaman ini contoh tampilan, bukan data layar kerja.</p>
      <nav aria-label="Daftar komponen">
        <a v-for="d in DAFTAR" :key="d.id" :href="`#${d.id}`">{{ d.label }}</a>
      </nav>
    </header>

    <main class="isi">
      <section :id="DAFTAR[0]!.id" aria-labelledby="h-tombol" class="kartu bagian">
        <h2 id="h-tombol">Tombol</h2>
        <div class="baris">
          <Tombol varian="utama">Utama</Tombol>
          <Tombol>Kedua</Tombol>
          <Tombol varian="bahaya">Bahaya</Tombol>
          <Tombol varian="tautan">Tautan</Tombol>
          <Tombol varian="utama" disabled>Nonaktif</Tombol>
          <Tombol varian="utama" :sibuk="sibuk" :disabled="sibuk" @click="cobaSibuk">{{ sibuk ? 'Memproses…' : 'Coba sibuk' }}</Tombol>
        </div>
        <div class="sempit"><Tombol varian="utama" ukuran="besar" penuh>Besar lebar penuh</Tombol></div>
      </section>

      <section :id="DAFTAR[1]!.id" aria-labelledby="h-pesan" class="kartu bagian">
        <h2 id="h-pesan">Pesan dan lencana</h2>
        <div class="tumpuk">
          <Pesan jenis="info">Sesi berakhir karena tidak ada aktivitas.</Pesan>
          <Pesan jenis="ok">Perubahan tersimpan.</Pesan>
          <Pesan jenis="peringatan">Izin sensitif butuh persetujuan pemilik data.</Pesan>
          <Pesan jenis="error">Nama pengguna atau kata sandi salah.</Pesan>
        </div>
        <div class="baris">
          <Lencana jenis="ok">Aktif</Lencana>
          <Lencana jenis="peringatan">Akan habis</Lencana>
          <Lencana jenis="bahaya">Terkunci</Lencana>
          <Lencana jenis="info">Wajib MFA</Lencana>
          <Lencana>Nonaktif</Lencana>
          <Lencana jenis="merek">Bawaan</Lencana>
        </div>
      </section>

      <section :id="DAFTAR[2]!.id" aria-labelledby="h-formulir" class="kartu bagian">
        <h2 id="h-formulir">Formulir</h2>
        <div class="sempit">
          <KolomIsian v-slot="a" label="Nama pengguna" petunjuk="Huruf kecil dan titik.">
            <input v-bind="a" v-model="nama" class="isian isian-besar" autocomplete="off" />
          </KolomIsian>
          <KolomIsian v-slot="a" label="Kata sandi" error="Kata sandi wajib diisi." wajib>
            <IsianSandi v-bind="a" v-model="sandi" autocomplete="new-password" />
          </KolomIsian>
          <KolomIsian v-slot="a" label="Kode verifikasi">
            <input v-bind="a" class="isian isian-besar isian-kode" inputmode="numeric" value="123 456" />
          </KolomIsian>
        </div>
      </section>

      <section :id="DAFTAR[3]!.id" aria-labelledby="h-keadaan" class="kartu bagian">
        <h2 id="h-keadaan">Keadaan kosong, error, memuat</h2>
        <div class="kisi">
          <KeadaanKosong judul="Belum ada lokasi" uraian="Tambahkan gedung pertama atau impor dari CSV.">
            <template #aksi><Tombol varian="utama">Tambah lokasi</Tombol></template>
          </KeadaanKosong>
          <KeadaanKosong jenis="error" judul="Data gagal dimuat" uraian="Periksa jaringan lalu coba lagi.">
            <template #aksi><Tombol>Coba lagi</Tombol></template>
          </KeadaanKosong>
          <KeadaanKosong jenis="memuat" judul="Memuat lokasi…" />
        </div>
      </section>

      <section :id="DAFTAR[4]!.id" aria-labelledby="h-kpi" class="kartu bagian">
        <h2 id="h-kpi">Kartu angka (hanya data asli)</h2>
        <div class="kisi">
          <KpiCard label="Contoh angka" :nilai="1250" satuan="baris" keterangan="Contoh tampilan" />
          <KpiCard label="Belum ada data" :nilai="null" />
          <KpiCard label="Perlu perhatian" :nilai="3" status="peringatan" keterangan="Contoh tampilan" />
          <KpiCard label="Memuat" :nilai="null" memuat />
        </div>
      </section>

      <section :id="DAFTAR[5]!.id" aria-labelledby="h-tabel" class="kartu bagian">
        <h2 id="h-tabel">Tabel data</h2>
        <h3>Berkursor dengan "Muat lagi", baris bisa dipilih</h3>
        <DataTable
          :kolom="kolom"
          :baris="halaman"
          :kunci-baris="(b) => b.id"
          label="Contoh baris"
          :ada-lagi="halaman.length < 20"
          :memuat-lagi="memuatLagi"
          dapat-dipilih
          :terpilih="terpilih"
          @muat-lagi="muatLagi"
          @pilih="(b) => (terpilih = b.id)"
        >
          <template #sel-jenis="{ baris }"><Lencana tanpa-ikon>{{ baris.jenis }}</Lencana></template>
        </DataTable>
        <h3>Gulir virtual (2.000 baris contoh)</h3>
        <DataTable :kolom="kolom" :baris="barisVirtual" :kunci-baris="(b) => b.id" label="Baris virtual" virtual :tinggi-maks="320" />
        <div class="kisi">
          <DataTable :kolom="kolom" :baris="[]" :kunci-baris="(b: Baris) => b.id" label="Tabel kosong" teks-kosong="Belum ada baris." />
          <DataTable :kolom="kolom" :baris="[]" :kunci-baris="(b: Baris) => b.id" label="Tabel error" error="Server tidak menjawab." />
          <DataTable :kolom="kolom" :baris="[]" :kunci-baris="(b: Baris) => b.id" label="Tabel memuat" memuat />
        </div>
      </section>

      <section :id="DAFTAR[6]!.id" aria-labelledby="h-pohon" class="kartu bagian">
        <h2 id="h-pohon">Pohon data</h2>
        <div class="sempit">
          <PohonData v-model:terpilih="simpulTerpilih" v-model:terbuka="simpulTerbuka" :simpul="pohon" label="Contoh lokasi" />
          <p class="catatan-kecil">Terpilih: {{ simpulTerpilih ?? '—' }}</p>
        </div>
      </section>

      <section :id="DAFTAR[7]!.id" aria-labelledby="h-bertahap" class="kartu bagian">
        <h2 id="h-bertahap">Formulir bertahap</h2>
        <FormulirBertahap v-model="langkahAktif" :langkah="langkah" label="Langkah contoh" @selesai="tampilkanToast('Contoh selesai.')">
          <template #identitas><p>Isi langkah identitas.</p></template>
          <template #lokasi><p>Isi langkah lokasi.</p></template>
          <template #tinjau><p>Tinjau sebelum selesai.</p></template>
        </FormulirBertahap>
      </section>

      <section :id="DAFTAR[8]!.id" aria-labelledby="h-toast" class="kartu bagian">
        <h2 id="h-toast">Notifikasi toast</h2>
        <div class="baris">
          <Tombol @click="tampilkanToast('Perubahan tersimpan.')">Berhasil</Tombol>
          <Tombol @click="tampilkanToast('Sinkronisasi berjalan di latar.', 'info')">Info</Tombol>
          <Tombol @click="tampilkanToast('Server lambat menjawab.', 'peringatan')">Peringatan</Tombol>
          <Tombol varian="bahaya" @click="tampilkanToast('Gagal menyimpan. Coba lagi.', 'error')">Error</Tombol>
        </div>
      </section>

      <section :id="DAFTAR[9]!.id" aria-labelledby="h-cari" class="kartu bagian">
        <h2 id="h-cari">Pencarian (popover)</h2>
        <PopoverPencarian
          v-model:open="bukaCari"
          v-model:kueri="kueriCari"
          label="Cari contoh"
          label-isian="Kata kunci contoh"
          :item="itemCari"
          :status="statusCari"
          @pilih="(it) => tampilkanToast(`Dipilih: ${it.label}`, 'info')"
        />
      </section>

      <section :id="DAFTAR[10]!.id" aria-labelledby="h-lain" class="kartu bagian">
        <h2 id="h-lain">Dialog, tab, sakelar</h2>
        <div class="baris">
          <Tombol @click="dialog = true">Buka dialog</Tombol>
          <Sakelar v-model="sakelar" label="Contoh sakelar" />
        </div>
        <Tabs v-model="tab" label="Contoh tab" :daftar="[{ nilai: 'satu', label: 'Satu' }, { nilai: 'dua', label: 'Dua' }]">
          <template #satu><p>Isi tab satu.</p></template>
          <template #dua><p>Isi tab dua.</p></template>
        </Tabs>
        <Dialog v-model:open="dialog" judul="Contoh dialog" uraian="Untuk konfirmasi tindakan berisiko.">
          <p>Isi dialog.</p>
          <template #aksi>
            <Tombol @click="dialog = false">Batal</Tombol>
            <Tombol varian="bahaya" @click="dialog = false">Lanjutkan</Tombol>
          </template>
        </Dialog>
      </section>
    </main>
    <NotifikasiToast />
  </div>
</template>

<style scoped>
.katalog {
  min-height: 100vh;
  background: var(--bg);
  color: var(--text);
}
.kepala {
  padding: var(--space-6) var(--space-6) var(--space-4);
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}
.kepala h1 {
  margin: 0 0 var(--space-1);
  font-size: var(--text-title);
}
.kepala p {
  margin: 0 0 var(--space-3);
  color: var(--muted);
}
.kepala nav {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2) var(--space-4);
  font-size: 13px;
}
.kepala a {
  display: inline-flex;
  align-items: center;
  min-height: 32px;
  color: var(--brand-600);
}
.isi {
  display: grid;
  gap: var(--space-4);
  max-width: 1200px;
  margin: 0 auto;
  padding: var(--space-6);
}
.bagian {
  padding: var(--space-4);
  scroll-margin-top: var(--space-4);
}
.bagian h2 {
  margin: 0 0 var(--space-3);
  font-size: 16px;
}
.bagian h3 {
  margin: var(--space-4) 0 var(--space-2);
  font-size: 13px;
  color: var(--muted);
}
.baris {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}
.tumpuk {
  display: grid;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}
.sempit {
  max-width: 420px;
}
.kisi {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: var(--space-3);
  margin-top: var(--space-3);
}
.catatan-kecil {
  font-size: 12.5px;
  color: var(--muted);
}
@media (max-width: 767.98px) {
  .kepala,
  .isi {
    padding: var(--space-4);
  }
}
</style>
