<script setup lang="ts">
import { useInfiniteQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { formatTanggal, formatTanggalWaktu } from '@/shared/utils/waktu'
import { versiTarif } from '../api'
import { KUNCI_TARIF, formatRupiah } from '../tarif'
import type { HargaTarif, Tarif } from '../tipe'

/** Rincian satu tarif: harga berlaku per kelas dengan komponen, harga terjadwal, dan riwayat versi (kontrak v0.18.0). */
const props = defineProps<{ tarif: Tarif | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ usulkan: [t: Tarif] }>()

const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_TARIF.versi(props.tarif?.id ?? '')),
  queryFn: ({ pageParam }) => versiTarif(props.tarif!.id, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  enabled: computed(() => buka.value && !!props.tarif),
})
const versi = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const tgl = (d: string) => formatTanggal(`${d}T00:00:00`)
const tabel = (h: HargaTarif[]) => h
</script>

<template>
  <Dialog v-if="tarif" v-model:open="buka" :judul="`${tarif.kode} · ${tarif.nama}`" :uraian="`${tarif.kelompok || 'Tanpa kelompok'} · berlaku sejak ${tgl(tarif.berlaku_dari)}`" lebar="lebar">
    <p v-if="tarif.usulan_terbuka" class="catatan catatan-info">
      Ada usulan yang sedang berjalan:
      <RouterLink :to="{ path: '/master-data/usulan', query: { usulan: tarif.usulan_terbuka.id } }">{{ tarif.usulan_terbuka.nomor }}</RouterLink>.
    </p>
    <h3>Harga berlaku</h3>
    <div class="gulir">
      <table class="tabel-harga">
        <caption class="sr-only">Harga berlaku per kelas rawat</caption>
        <thead><tr><th scope="col">Kelas</th><th scope="col">Jasa sarana</th><th scope="col">Jasa pelayanan</th><th scope="col">BHP</th><th scope="col">Total</th></tr></thead>
        <tbody>
          <tr v-for="h in tabel(tarif.harga)" :key="h.kelas_rawat_id">
            <th scope="row">{{ h.kelas_rawat_label }}</th>
            <td>{{ formatRupiah(h.jasa_sarana) }}</td>
            <td>{{ formatRupiah(h.jasa_pelayanan) }}</td>
            <td>{{ formatRupiah(h.bhp) }}</td>
            <td><b>{{ formatRupiah(h.total) }}</b></td>
          </tr>
        </tbody>
      </table>
    </div>
    <template v-if="tarif.akan_berlaku">
      <h3>Akan berlaku {{ tgl(tarif.akan_berlaku.berlaku_dari) }}</h3>
      <div class="gulir">
        <table class="tabel-harga">
          <caption class="sr-only">Harga terjadwal per kelas rawat</caption>
          <thead><tr><th scope="col">Kelas</th><th scope="col">Jasa sarana</th><th scope="col">Jasa pelayanan</th><th scope="col">BHP</th><th scope="col">Total</th></tr></thead>
          <tbody>
            <tr v-for="h in tabel(tarif.akan_berlaku.harga)" :key="h.kelas_rawat_id">
              <th scope="row">{{ h.kelas_rawat_label }}</th>
              <td>{{ formatRupiah(h.jasa_sarana) }}</td>
              <td>{{ formatRupiah(h.jasa_pelayanan) }}</td>
              <td>{{ formatRupiah(h.bhp) }}</td>
              <td><b>{{ formatRupiah(h.total) }}</b></td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
    <h3>Riwayat versi</h3>
    <p v-if="kueri.isPending.value" class="ket" role="status">Memuat riwayat…</p>
    <p v-else-if="kueri.isError.value" class="ket" role="alert">{{ pesanError(kueri.error.value) }}</p>
    <ol v-else class="versi">
      <li v-for="v in versi" :key="v.id">
        <b>Versi {{ v.versi }}</b>
        <span class="ket">berlaku {{ tgl(v.berlaku_dari) }} · {{ v.diubah_oleh?.nama ?? 'Sistem' }}, {{ formatTanggalWaktu(v.diubah_pada) }}</span>
        <RouterLink v-if="v.usulan" :to="{ path: '/master-data/usulan', query: { usulan: v.usulan.id } }">{{ v.usulan.nomor }}</RouterLink>
        <ul>
          <li v-for="t in v.tampilan" :key="t.isian">{{ t.label_isian }}: <span class="ket">{{ t.lama ?? '—' }}</span> → <b>{{ t.baru ?? '—' }}</b></li>
        </ul>
        <span class="ket">Dasar: {{ v.dasar }}</span>
      </li>
    </ol>
    <button v-if="kueri.hasNextPage.value" type="button" class="tombol" :disabled="kueri.isFetchingNextPage.value" @click="kueri.fetchNextPage()">Muat lagi</button>
    <template #aksi>
      <button type="button" class="tombol" @click="buka = false">Tutup</button>
      <button v-can="'master_data.usulan.input'" type="button" class="tombol tombol-utama" :disabled="tarif.status === 'nonaktif'" @click="emit('usulkan', tarif)">Usulkan perubahan</button>
    </template>
  </Dialog>
</template>

<style scoped>
h3 {
  margin: var(--space-3) 0 var(--space-2);
  font-size: 13px;
}
.gulir {
  overflow-x: auto;
}
.tabel-harga {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  font-variant-numeric: tabular-nums;
}
.tabel-harga th,
.tabel-harga td {
  padding: var(--space-1) var(--space-2);
  border-bottom: 1px solid var(--border);
  text-align: right;
  white-space: nowrap;
}
.tabel-harga th:first-child {
  text-align: left;
}
.versi {
  margin: 0;
  padding-left: var(--space-4);
}
.versi li {
  margin-bottom: var(--space-3);
}
.versi ul {
  margin: var(--space-1) 0;
  padding-left: var(--space-4);
}
.ket {
  margin-right: var(--space-2);
  color: var(--muted);
  font-size: 12.5px;
}
</style>
