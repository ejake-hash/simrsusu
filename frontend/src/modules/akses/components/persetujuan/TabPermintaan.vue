<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, nextTick, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { daftarPermintaan, tolakPermintaan } from '../../apiPersetujuan'
import { indeksPohon } from '../../matriks'
import { KUNCI, pesanError, usePohonMenu } from '../../pengaturan'
import { LABEL_STATUS_PERMINTAAN } from '../../persetujuan'
import type { PermintaanAkses, StatusPermintaan } from '../../tipePersetujuan'
import DialogAkhiri from '../pengaturan/DialogAkhiri.vue'
import DialogIzinKhusus from '../pengaturan/DialogIzinKhusus.vue'
import DialogPenugasan from '../pengaturan/DialogPenugasan.vue'

/**
 * Permintaan akses dari layar "Akses ditolak" (GET /permintaan-akses, izin pengaturan.akun.lihat). Menindaklanjuti =
 * membuat pengecualian (sasaran permintaan) atau menugaskan peran, dengan `permintaan_akses_id` dan dasar = nomor
 * permintaan; pemberian berisiko tetap menunggu persetujuan. Menolak butuh alasan (pengaturan.akun.perbaiki).
 */
const qc = useQueryClient()
const status = ref<StatusPermintaan | ''>('menunggu')
const filter = computed(() => ({ status: status.value || undefined }))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI.daftarPermintaan(filter.value)),
  queryFn: ({ pageParam }) => daftarPermintaan(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const pohon = usePohonMenu()
const indeks = computed(() => (pohon.data.value ? indeksPohon(pohon.data.value) : null))

const bolehTindak = computed(() => boleh('pengaturan.akun.input'))
const bolehTolak = computed(() => boleh('pengaturan.akun.perbaiki'))
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'nomor', label: 'Nomor', lebar: '150px', prioritas: 2 },
  { kunci: 'akun', label: 'Akun' },
  { kunci: 'sasaran', label: 'Akses yang diminta' },
  { kunci: 'alasan', label: 'Alasan', prioritas: 3 },
  { kunci: 'status', label: 'Status', lebar: '130px' },
  ...(bolehTindak.value || bolehTolak.value ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '240px' }] : []),
])

const dipilih = ref<PermintaanAkses | null>(null)
const dialogKhusus = ref(false)
const dialogPeran = ref(false)
const dialogTolak = ref(false)
async function tindak(p: PermintaanAkses, cara: 'khusus' | 'peran' | 'tolak') {
  dipilih.value = p
  // Dialog dipasang dulu (v-if dipilih), baru dibuka: isian awal diisi saat `open` berubah menjadi true.
  await nextTick()
  if (cara === 'khusus') dialogKhusus.value = true
  else if (cara === 'peran') dialogPeran.value = true
  else dialogTolak.value = true
}
function selesai(pesan: string) {
  tampilkanToast(`${dipilih.value?.nomor ?? 'Permintaan'}: ${pesan}`)
  void qc.invalidateQueries({ queryKey: KUNCI.permintaan })
  void qc.invalidateQueries({ queryKey: KUNCI.semuaAkun })
  void qc.invalidateQueries({ queryKey: KUNCI.persetujuan })
}
async function tolak(alasan: string) {
  const p = await tolakPermintaan(dipilih.value!.id, alasan)
  tampilkanToast(`Permintaan ${p.nomor} ditolak.`)
  void qc.invalidateQueries({ queryKey: KUNCI.permintaan })
}
</script>

<template>
  <section class="kartu" aria-labelledby="judul-permintaan">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-permintaan">Permintaan akses</h2>
        <p>Diajukan pengguna dari layar "Akses ditolak". Tindak lanjuti dengan pengecualian atau peran, atau tolak dengan alasan.</p>
      </div>
      <label class="sr-only" for="status-permintaan">Status permintaan</label>
      <select id="status-permintaan" v-model="status" class="isian status">
        <option value="menunggu">Menunggu</option>
        <option value="ditindaklanjuti">Ditindaklanjuti</option>
        <option value="ditolak">Ditolak</option>
        <option value="">Semua status</option>
      </select>
    </div>
    <DataTable
      :kolom="kolom"
      :baris="baris"
      :kunci-baris="(p: PermintaanAkses) => p.id"
      label="Daftar permintaan akses"
      :memuat="kueri.isPending.value"
      :memuat-lagi="kueri.isFetchingNextPage.value"
      :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
      :ada-lagi="kueri.hasNextPage.value"
      teks-kosong="Tidak ada permintaan akses."
      @muat-lagi="kueri.fetchNextPage()"
      @coba-lagi="kueri.refetch()"
    >
      <template #sel-nomor="{ baris: p }"><code>{{ p.nomor }}</code></template>
      <template #sel-akun="{ baris: p }">
        <b>{{ p.akun.nama }}</b>
        <span class="ket">{{ p.akun.nama_pengguna }} · {{ formatTanggalWaktu(p.dibuat_pada) }}</span>
      </template>
      <template #sel-sasaran="{ baris: p }">{{ p.sasaran_label }}</template>
      <template #sel-alasan="{ baris: p }">{{ p.alasan }}</template>
      <template #sel-status="{ baris: p }">
        <span class="pil" :class="p.status === 'menunggu' ? 'pil-peringatan' : p.status === 'ditolak' ? 'pil-netral' : 'pil-ok'">{{ LABEL_STATUS_PERMINTAAN[p.status] }}</span>
        <span v-if="p.catatan_keputusan" class="ket">{{ p.catatan_keputusan }}</span>
      </template>
      <template #sel-aksi="{ baris: p }">
        <span v-if="p.status === 'menunggu'" class="aksi">
          <template v-if="bolehTindak">
            <Tombol :aria-label="`Beri pengecualian untuk ${p.nomor}`" :disabled="!indeks" @click="tindak(p, 'khusus')">Beri akses</Tombol>
            <Tombol :aria-label="`Tugaskan peran untuk ${p.nomor}`" @click="tindak(p, 'peran')">Tugaskan peran</Tombol>
          </template>
          <Tombol v-if="bolehTolak" varian="bahaya" :aria-label="`Tolak ${p.nomor}`" @click="tindak(p, 'tolak')">Tolak</Tombol>
        </span>
      </template>
    </DataTable>

    <template v-if="dipilih">
      <DialogIzinKhusus v-if="indeks" v-model:open="dialogKhusus" :akun-id="dipilih.akun.id" :nama-akun="dipilih.akun.nama" :indeks="indeks" :permintaan="dipilih" @selesai="selesai" />
      <DialogPenugasan v-model:open="dialogPeran" :akun-id="dipilih.akun.id" :nama-akun="dipilih.akun.nama" :permintaan="dipilih" @selesai="selesai" />
      <DialogAkhiri
        v-model:open="dialogTolak"
        :judul="`Tolak permintaan ${dipilih.nomor}?`"
        :akibat="`${dipilih.akun.nama} tidak mendapat akses ${dipilih.sasaran_label}; alasan Anda terlihat oleh pemohon.`"
        :aksi="tolak"
        label-alasan="Alasan penolakan"
        label-aksi="Tolak permintaan"
      />
    </template>
  </section>
</template>

<style scoped>
.status {
  width: auto;
  min-width: 160px;
}
.ket {
  display: block;
  color: var(--muted);
  font-size: 12px;
}
.aksi {
  display: inline-flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
}
</style>
