<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { boleh } from '@/shared/composables/usePermission'
import { refDebounced } from '@/shared/composables/refDebounced'
import type { Berversi } from '../../api'
import { ambilPoliklinik, daftarPoliklinik } from '../../apiLokasi'
import { KUNCI_LOKASI } from '../../lokasi'
import type { Poliklinik } from '../../tipeLokasi'
import DialogStatus from '../DialogStatus.vue'
import DialogPoliklinik from './DialogPoliklinik.vue'

/** Poliklinik (GET /poliklinik berkursor): unit pengelola, ruang (penunjuk arah), kode poli BPJS dari RS. */
const qc = useQueryClient()
const cari = ref('')
const cariTunda = refDebounced(cari, 300)
const status = ref<'aktif' | 'nonaktif' | ''>('aktif')
const filter = computed(() => ({ cari: cariTunda.value.trim() || undefined, status: status.value || undefined }))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_LOKASI.daftarPoli(filter.value)),
  queryFn: ({ pageParam }) => daftarPoliklinik(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'kode', label: 'Kode', lebar: '130px' },
  { kunci: 'nama', label: 'Poliklinik' },
  { kunci: 'lokasi', label: 'Ruang', prioritas: 2 },
  { kunci: 'kode_poli_bpjs', label: 'Kode BPJS', prioritas: 3, lebar: '120px' },
  { kunci: 'status', label: 'Status', lebar: '100px' },
  ...(boleh('master_data.organisasi.perbaiki') ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '200px' }] : []),
])

const dialogPoli = ref(false)
const dialogStatus = ref(false)
const target = ref<Berversi<Poliklinik> | null>(null)
const memuatId = ref<string | null>(null)
async function buka(p: Poliklinik | null, aksi: 'ubah' | 'status' = 'ubah') {
  if (!p) {
    target.value = null
    dialogPoli.value = true
    return
  }
  memuatId.value = p.id
  try {
    target.value = await ambilPoliklinik(p.id)
    if (aksi === 'ubah') dialogPoli.value = true
    else dialogStatus.value = true
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
  } finally {
    memuatId.value = null
  }
}
function selesai() {
  void qc.invalidateQueries({ queryKey: KUNCI_LOKASI.poli })
}
</script>

<template>
  <section class="kartu" aria-labelledby="judul-poliklinik">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-poliklinik">Poliklinik</h2>
        <p>Kode poli BPJS diisi RS dari referensi resmi.</p>
      </div>
      <button v-can="'master_data.organisasi.input'" type="button" class="tombol" @click="buka(null)"><Ikon nama="tambah" :ukuran="16" />Tambah poliklinik</button>
    </div>
    <div class="filter">
      <label class="sr-only" for="cari-poli">Cari poliklinik</label>
      <input id="cari-poli" v-model="cari" type="search" class="isian" placeholder="Cari kode atau nama" />
      <label class="sr-only" for="status-poli">Status poliklinik</label>
      <select id="status-poli" v-model="status" class="isian">
        <option value="aktif">Aktif</option>
        <option value="nonaktif">Nonaktif</option>
        <option value="">Semua status</option>
      </select>
    </div>
    <DataTable
      :kolom="kolom"
      :baris="baris"
      :kunci-baris="(p: Poliklinik) => p.id"
      label="Daftar poliklinik"
      :memuat="kueri.isPending.value"
      :memuat-lagi="kueri.isFetchingNextPage.value"
      :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
      :ada-lagi="kueri.hasNextPage.value"
      teks-kosong="Belum ada poliklinik yang cocok."
      @muat-lagi="kueri.fetchNextPage()"
      @coba-lagi="kueri.refetch()"
    >
      <template #sel-kode="{ baris: p }"><code>{{ p.kode }}</code></template>
      <template #sel-nama="{ baris: p }">
        <b>{{ p.nama }}</b>
        <span class="ket">{{ p.unit.nama }}</span>
      </template>
      <template #sel-lokasi="{ baris: p }">{{ p.lokasi ? `${p.lokasi.kode_lengkap} · ${p.lokasi.nama}` : 'Belum dipetakan' }}</template>
      <template #sel-kode_poli_bpjs="{ baris: p }">{{ p.kode_poli_bpjs ?? '—' }}</template>
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
    <DialogPoliklinik v-model:open="dialogPoli" :poli="target" @selesai="selesai" @muat-ulang="selesai" />
    <DialogStatus v-model:open="dialogStatus" jenis="poli" :target="target" @selesai="selesai" @muat-ulang="selesai" />
  </section>
</template>

<style scoped>
.filter {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 160px;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}
.ket {
  display: block;
  color: var(--muted);
  font-size: 12px;
}
.aksi {
  display: inline-flex;
  gap: var(--space-2);
}
@media (max-width: 599.98px) {
  .filter {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
