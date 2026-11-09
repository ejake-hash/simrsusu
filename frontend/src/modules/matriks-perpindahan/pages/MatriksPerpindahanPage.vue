<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import type { UsulanDataInduk } from '@/modules/usulan/tipe'
import { boleh } from '@/shared/composables/usePermission'
import { daftarMatriks } from '../api'
import DialogUsulanMatriks from '../components/DialogUsulanMatriks.vue'
import { keteranganModulLain, KUNCI_MATRIKS, LABEL_POS, LABEL_STATUS_ATURAN, labelSel, POS_ASAL } from '../matriks'
import type { AturanPerpindahan, PosAsalPerpindahan, StatusAturan } from '../tipe'

/**
 * Master Data → Matriks perpindahan (tiket 2.26, kontrak v0.33.0; FR-00-02, PRD 7.00.3). Setiap sel = pasangan pos asal →
 * pos tujuan yang boleh diperintahkan beserta izin pemberi perintahnya. Hanya baca: menambah, mengubah, atau
 * menonaktifkan sel lewat usulan data induk berjenjang (jenis data `matriks_perpindahan`).
 */
const qc = useQueryClient()
const router = useRouter()
const posAsal = ref<PosAsalPerpindahan | ''>('')
const status = ref<StatusAturan | ''>('')
const filter = computed(() => ({ pos_asal: posAsal.value || undefined, status: status.value || undefined }))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_MATRIKS.daftar(filter.value)),
  queryFn: ({ pageParam }) => daftarMatriks(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const bolehUsul = computed(() => boleh('master_data.usulan.input'))
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'sel', label: 'Asal → tujuan' },
  { kunci: 'izin', label: 'Izin pemberi perintah', lebar: '230px' },
  { kunci: 'keterangan', label: 'Keterangan', prioritas: 2 },
  { kunci: 'status', label: 'Status', lebar: '120px' },
  ...(bolehUsul.value ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '190px' }] : []),
])

const dialog = ref(false)
const selUsul = ref<AturanPerpindahan | null>(null)
function usulkan(a: AturanPerpindahan | null) {
  selUsul.value = a
  dialog.value = true
}
function diajukan(u: UsulanDataInduk) {
  void qc.invalidateQueries({ queryKey: KUNCI_MATRIKS.semua })
  void qc.invalidateQueries({ queryKey: ['usulan'] })
  tampilkanToast(`${u.nomor} diajukan; menunggu verifikasi.`)
  if (boleh('master_data.usulan.lihat')) void router.push({ path: '/master-data/usulan', query: { usulan: u.id } })
}
</script>

<template>
  <HalamanModul
    izin="master_data.matriks_perpindahan.lihat"
    judul="Matriks perpindahan"
    uraian="Perpindahan pasien antar-pos yang boleh diperintahkan dan izin yang wajib dipegang pemberi perintah. Perintah di luar matriks ditolak. Perubahan lewat usulan, berlaku pada tanggal yang disahkan."
  >
    <section class="kartu" aria-labelledby="judul-matriks">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-matriks">Sel matriks</h2>
          <p>Sel nonaktif tetap tersimpan; perintah ke tujuan itu ditolak sampai sel diaktifkan lagi lewat usulan.</p>
        </div>
        <span class="aksi-kepala">
          <RouterLink v-can="'master_data.usulan.lihat'" class="tombol" to="/master-data/usulan">Lihat usulan</RouterLink>
          <button v-if="bolehUsul" type="button" class="tombol" @click="usulkan(null)"><Ikon nama="tambah" :ukuran="16" />Usulkan sel baru</button>
        </span>
      </div>
      <div class="filter">
        <label class="sr-only" for="asal-matriks">Pos asal</label>
        <select id="asal-matriks" v-model="posAsal" class="isian">
          <option value="">Semua pos asal</option>
          <option v-for="p in POS_ASAL" :key="p" :value="p">{{ LABEL_POS[p] }}</option>
        </select>
        <label class="sr-only" for="status-matriks">Status sel</label>
        <select id="status-matriks" v-model="status" class="isian">
          <option value="">Semua status</option>
          <option value="aktif">Aktif</option>
          <option value="nonaktif">Nonaktif</option>
        </select>
      </div>
      <DataTable
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(a: AturanPerpindahan) => a.id"
        label="Daftar sel matriks perpindahan"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        teks-kosong="Tidak ada sel yang cocok dengan filter."
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-sel="{ baris: a }">
          <b>{{ LABEL_POS[a.pos_asal] }}</b>
          <span aria-hidden="true"> → </span><span class="sr-only"> ke </span>
          <b>{{ LABEL_POS[a.pos_tujuan] }}</b>
          <span v-if="keteranganModulLain(a)" class="ket modul-lain"><Ikon nama="info" :ukuran="14" />{{ keteranganModulLain(a) }}</span>
        </template>
        <template #sel-izin="{ baris: a }"><code>{{ a.izin_perintah }}</code></template>
        <template #sel-keterangan="{ baris: a }">
          <span v-if="a.keterangan">{{ a.keterangan }}</span>
          <span v-else class="ket">Tanpa keterangan</span>
        </template>
        <template #sel-status="{ baris: a }">
          <Lencana :jenis="a.status === 'aktif' ? 'ok' : 'peringatan'">{{ LABEL_STATUS_ATURAN[a.status] }}</Lencana>
        </template>
        <template #sel-aksi="{ baris: a }">
          <Tombol :aria-label="`Usulkan perubahan sel ${labelSel(a)}`" @click="usulkan(a)">Usulkan perubahan</Tombol>
        </template>
      </DataTable>
    </section>
    <DialogUsulanMatriks v-model:open="dialog" :sel="selUsul" @selesai="diajukan" />
  </HalamanModul>
</template>

<style scoped>
.filter {
  display: grid;
  grid-template-columns: 220px 180px;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}
.aksi-kepala {
  display: inline-flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
.ket {
  display: block;
  color: var(--muted);
  font-size: 12px;
}
.modul-lain {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-top: 2px;
}
code {
  font-family: var(--font-mono);
  font-size: 12.5px;
  overflow-wrap: anywhere;
}
@media (max-width: 599.98px) {
  .filter {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
