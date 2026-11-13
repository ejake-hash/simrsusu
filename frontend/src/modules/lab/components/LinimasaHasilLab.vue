<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, nextTick, ref, useId } from 'vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { linimasaHasilLab } from '../api'
import { bolehAdendum, KUNCI_LAB } from '../lab'
import type { HasilLab } from '../tipe'
import DialogAdendumHasilLab from './DialogAdendumHasilLab.vue'
import TampilanHasilLab from './TampilanHasilLab.vue'

/**
 * Linimasa hasil lab tervalidasi satu episode (tiket 2.31; GET /episode/{id}/hasil-lab, FR-00-09, 7.00.5 butir 3): urut
 * waktu hasil keluar, terbaru di atas (server), penanda berteks, adendum bertanda di bawah hasil asli. Kursor "Muat lagi".
 * 403 = akun ini tidak bisa membuka hasil episode: pesan netral tanpa data. Membuka linimasa menyelesaikan tugas tindak
 * lanjut hasil lab milik akun ini (server).
 */
const props = defineProps<{ episodeId: string }>()
const qc = useQueryClient()
const idJudul = useId()
const kunci = computed(() => KUNCI_LAB.linimasa(props.episodeId))

const kueri = useInfiniteQuery({
  queryKey: kunci,
  queryFn: ({ pageParam }) => linimasaHasilLab(props.episodeId, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const hasil = computed<HasilLab[]>(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const ditolak = computed(() => kueri.error.value instanceof ApiError && kueri.error.value.status === 403)
const segarkan = () => qc.invalidateQueries({ queryKey: kunci.value })

const sasaran = ref<HasilLab | null>(null)
const bukaAdendum = ref(false)
const pesanOk = ref<string | null>(null)
const elOk = ref<HTMLElement | null>(null)
let fokusOk = false
function mintaAdendum(h: HasilLab) {
  sasaran.value = h
  fokusOk = false
  bukaAdendum.value = true
}
async function selesai(h: HasilLab) {
  pesanOk.value = `Adendum ${h.nama} tercatat. Hasil asli tetap tersimpan.`
  fokusOk = true
  await nextTick()
  await segarkan()
}
</script>

<template>
  <section :aria-labelledby="idJudul" class="linimasa">
    <h3 :id="idJudul">Hasil laboratorium</h3>
    <p v-if="ditolak" class="ket">Hasil laboratorium episode ini tidak bisa dibuka dengan akses akun Anda.</p>
    <template v-else>
      <p v-if="kueri.isPending.value" class="ket" role="status">Memuat hasil laboratorium…</p>
      <div v-else-if="kueri.isError.value" class="galat">
        <Pesan jenis="error">{{ pesanError(kueri.error.value) }}</Pesan>
        <Tombol @click="kueri.refetch()">Coba lagi</Tombol>
      </div>
      <p v-else-if="!hasil.length" class="ket">Belum ada hasil laboratorium yang divalidasi di episode ini.</p>
      <div v-if="pesanOk" ref="elOk" tabindex="-1" class="ok">
        <Pesan jenis="ok" role="status">{{ pesanOk }}</Pesan>
      </div>
      <ol v-if="hasil.length" class="daftar">
        <li v-for="h in hasil" :key="h.id">
          <div class="kepala">
            <span><code>{{ h.kode_item }}</code> <b>{{ h.nama }}</b></span>
            <span class="waktu">Keluar {{ formatTanggalWaktu(h.dikeluarkan_pada) ?? 'waktu tidak tercatat' }}</span>
          </div>
          <TampilanHasilLab :hasil="h" pelaku />
          <div v-if="bolehAdendum(h)" class="aksi">
            <Tombol :aria-label="`Adendum ${h.nama}`" @click="mintaAdendum(h)">Adendum</Tombol>
          </div>
        </li>
      </ol>
      <Tombol v-if="kueri.hasNextPage.value" :sibuk="kueri.isFetchingNextPage.value" @click="kueri.fetchNextPage()">Muat lagi</Tombol>
    </template>
    <DialogAdendumHasilLab v-model:open="bukaAdendum" :hasil="sasaran" :fokus-sesudah-tutup="() => (fokusOk ? elOk : null)" @selesai="selesai" @muat-ulang="segarkan" />
  </section>
</template>

<style scoped>
.linimasa {
  display: grid;
  gap: var(--space-2);
}
.linimasa h3 {
  margin: 0;
}
.ket {
  margin: 0;
  font-size: 13px;
  color: var(--muted);
}
.galat {
  display: grid;
  gap: var(--space-2);
  justify-items: start;
}
.ok:focus-visible {
  outline: 2px solid var(--brand-600);
  outline-offset: 2px;
  border-radius: var(--radius-input);
}
.daftar {
  display: grid;
  gap: var(--space-3);
  margin: 0;
  padding: 0;
  list-style: none;
}
.daftar li {
  display: grid;
  gap: var(--space-1);
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
  background: var(--surface);
}
.kepala {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: var(--space-2);
}
.waktu {
  font-size: 12px;
  color: var(--muted);
  font-variant-numeric: tabular-nums;
}
.aksi {
  display: flex;
  gap: var(--space-2);
}
</style>
