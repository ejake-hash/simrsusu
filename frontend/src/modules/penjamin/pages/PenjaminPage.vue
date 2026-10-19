<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { refDebounced } from '@/shared/composables/refDebounced'
import { boleh } from '@/shared/composables/usePermission'
import { ambilPenjamin, daftarPenjamin } from '../api'
import DialogPenjamin from '../components/DialogPenjamin.vue'
import DialogStatusPenjamin from '../components/DialogStatusPenjamin.vue'
import { formatHari, formatPlafon, JENIS_PENJAMIN, KUNCI_PENJAMIN, LABEL_JENIS_PENJAMIN } from '../penjamin'
import type { JenisPenjamin, Penjamin, StatusPenjamin } from '../tipe'

/**
 * Master Data → Penjamin (tiket 2.1, kontrak v0.17.0). Penjamin nonaktif tidak muncul di pilihan pendaftaran tetapi tetap
 * terbaca di riwayat. Kelas rawat tetap di Organisasi → Kelas rawat (1.8b).
 */
const qc = useQueryClient()
const cari = ref('')
const cariTunda = refDebounced(cari, 300)
const status = ref<StatusPenjamin | ''>('aktif')
const jenis = ref<JenisPenjamin | ''>('')
const filter = computed(() => ({ cari: cariTunda.value.trim() || undefined, status: status.value || undefined, jenis: jenis.value || undefined }))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_PENJAMIN.daftar(filter.value)),
  queryFn: ({ pageParam }) => daftarPenjamin(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'kode', label: 'Kode', lebar: '150px' },
  { kunci: 'nama', label: 'Penjamin' },
  { kunci: 'jenis', label: 'Jenis', prioritas: 2, lebar: '180px' },
  { kunci: 'masa_berlaku_gl_hari', label: 'Masa berlaku GL', prioritas: 3, lebar: '150px' },
  { kunci: 'jatuh_tempo_hari', label: 'Jatuh tempo', prioritas: 3, lebar: '130px' },
  { kunci: 'plafon', label: 'Plafon', prioritas: 3, rata: 'kanan', lebar: '170px' },
  { kunci: 'status', label: 'Status', lebar: '100px' },
  ...(boleh('master_data.penjamin.perbaiki') ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '200px' }] : []),
])

const dialogPenjamin = ref(false)
const dialogStatus = ref(false)
const target = ref<Berversi<Penjamin> | null>(null)
const memuatId = ref<string | null>(null)
/** Rincian dimuat dulu agar If-Match memakai versi terbaru, bukan versi saat daftar dibuka. */
async function buka(p: Penjamin | null, aksi: 'ubah' | 'status' = 'ubah') {
  if (!p) {
    target.value = null
    dialogPenjamin.value = true
    return
  }
  memuatId.value = p.id
  try {
    target.value = await ambilPenjamin(p.id)
    if (aksi === 'ubah') dialogPenjamin.value = true
    else dialogStatus.value = true
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
  } finally {
    memuatId.value = null
  }
}
function selesai() {
  void qc.invalidateQueries({ queryKey: KUNCI_PENJAMIN.semua })
}
</script>

<template>
  <HalamanModul
    izin="master_data.penjamin.lihat"
    judul="Penjamin"
    uraian="Penjamin yang dipakai pendaftaran dan penagihan, beserta masa berlaku surat jaminan, plafon, dan jatuh tempo. Setiap perubahan butuh dasar dan tercatat di jejak audit."
  >
    <section class="kartu" aria-labelledby="judul-penjamin">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-penjamin">Daftar penjamin</h2>
          <p>Penjamin nonaktif tidak muncul di pilihan pendaftaran, tetapi kunjungan dan tagihan lama tetap menampilkannya.</p>
        </div>
        <button v-can="'master_data.penjamin.input'" type="button" class="tombol" @click="buka(null)"><Ikon nama="tambah" :ukuran="16" />Tambah penjamin</button>
      </div>
      <div class="filter">
        <label class="sr-only" for="cari-penjamin">Cari penjamin</label>
        <input id="cari-penjamin" v-model="cari" type="search" class="isian" placeholder="Cari kode atau nama" />
        <label class="sr-only" for="jenis-penjamin">Jenis penjamin</label>
        <select id="jenis-penjamin" v-model="jenis" class="isian">
          <option value="">Semua jenis</option>
          <option v-for="j in JENIS_PENJAMIN" :key="j" :value="j">{{ LABEL_JENIS_PENJAMIN[j] }}</option>
        </select>
        <label class="sr-only" for="status-penjamin">Status penjamin</label>
        <select id="status-penjamin" v-model="status" class="isian">
          <option value="aktif">Aktif</option>
          <option value="nonaktif">Nonaktif</option>
          <option value="">Semua status</option>
        </select>
      </div>
      <DataTable
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(p: Penjamin) => p.id"
        label="Daftar penjamin"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        teks-kosong="Belum ada penjamin yang cocok. Ubah filter, atau tambahkan penjamin bila akun Anda berhak."
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-kode="{ baris: p }"><code>{{ p.kode }}</code></template>
        <template #sel-nama="{ baris: p }"><b>{{ p.nama }}</b></template>
        <template #sel-jenis="{ baris: p }">{{ LABEL_JENIS_PENJAMIN[p.jenis] }}</template>
        <template #sel-masa_berlaku_gl_hari="{ baris: p }">{{ formatHari(p.masa_berlaku_gl_hari) }}</template>
        <template #sel-jatuh_tempo_hari="{ baris: p }">{{ formatHari(p.jatuh_tempo_hari, 'Belum ditetapkan') }}</template>
        <template #sel-plafon="{ baris: p }">{{ formatPlafon(p.plafon) }}</template>
        <template #sel-status="{ baris: p }">
          <span class="pil" :class="p.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ p.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</span>
        </template>
        <template #sel-aksi="{ baris: p }">
          <span class="aksi">
            <Tombol :sibuk="memuatId === p.id" :aria-label="`Ubah ${p.nama}`" @click="buka(p)">Ubah</Tombol>
            <Tombol :sibuk="memuatId === p.id" :aria-label="`${p.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'} ${p.nama}`" @click="buka(p, 'status')">{{ p.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' }}</Tombol>
          </span>
        </template>
      </DataTable>
      <DialogPenjamin v-model:open="dialogPenjamin" :penjamin="target" @selesai="selesai" @muat-ulang="selesai" />
      <DialogStatusPenjamin v-model:open="dialogStatus" :target="target" @selesai="selesai" @muat-ulang="selesai" />
    </section>
  </HalamanModul>
</template>

<style scoped>
.filter {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 200px 160px;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}
.aksi {
  display: inline-flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
@media (max-width: 768px) {
  .filter {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
