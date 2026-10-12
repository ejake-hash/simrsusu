<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { tampilkanToast } from '@/design-system/components/toast'
import { pesanError } from '@/modules/akses/pengaturan'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { daftarNotifikasi, tandaiDibaca, tandaiSemuaDibaca } from '../api'
import { KUNCI_NOTIF } from '../notifikasi'
import type { HalamanNotifikasi, Notifikasi } from '../tipe'

/**
 * Daftar notifikasi (belum dibaca atau semua), "Muat lagi" berkursor. Membuka notifikasi = tandai dibaca lalu buka
 * tautannya; notifikasi kritis tidak ikut "Tandai semua dibaca" (server) sehingga dibuka satu per satu.
 */
const props = defineProps<{ status: 'belum_dibaca' | 'semua' }>()
const qc = useQueryClient()
const router = useRouter()
const sibuk = ref(false)

const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_NOTIF.daftar(props.status)),
  queryFn: async ({ pageParam }) => {
    const r = await daftarNotifikasi({ status: props.status }, pageParam, 20)
    return (r.berubah ? r.data : { data: [], meta: { kursor_berikutnya: null, jumlah_belum_dibaca: 0 } }) as HalamanNotifikasi
  },
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const daftar = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const belumDibaca = computed(() => kueri.data.value?.pages[0]?.meta.jumlah_belum_dibaca ?? 0)

function segarkan() {
  void qc.invalidateQueries({ queryKey: KUNCI_NOTIF.semua })
}
async function buka(n: Notifikasi) {
  try {
    if (!n.dibaca_pada) await tandaiDibaca(n.id)
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
    return
  }
  segarkan()
  if (n.tautan) await router.push(n.tautan)
}
async function semuaDibaca() {
  sibuk.value = true
  try {
    const n = await tandaiSemuaDibaca(daftar.value[0]?.dibuat_pada ?? null)
    tampilkanToast(n ? `${n} notifikasi ditandai dibaca.` : 'Tidak ada notifikasi yang bisa ditandai sekaligus.')
    segarkan()
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <div>
    <div v-if="belumDibaca" class="alat">
      <span class="ket">{{ belumDibaca }} belum dibaca. Notifikasi kritis dibuka satu per satu.</span>
      <button type="button" class="tombol" :disabled="sibuk" @click="semuaDibaca">Tandai semua dibaca</button>
    </div>
    <p v-if="kueri.isPending.value" class="keadaan" role="status">Memuat notifikasi…</p>
    <div v-else-if="kueri.isError.value" class="keadaan" role="alert">
      <p>{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <p v-else-if="!daftar.length" class="keadaan">{{ status === 'belum_dibaca' ? 'Semua notifikasi sudah dibaca.' : 'Belum ada notifikasi.' }}</p>
    <ul v-else class="daftar" :aria-label="status === 'belum_dibaca' ? 'Notifikasi belum dibaca' : 'Semua notifikasi'">
      <li v-for="n in daftar" :key="n.id" :class="{ dibaca: !!n.dibaca_pada, kritis: n.prioritas === 'kritis' }">
        <div class="isi">
          <span class="judul">
            <span v-if="n.prioritas === 'kritis'" class="pil pil-bahaya">Kritis</span>
            <span v-else-if="n.prioritas === 'penting'" class="pil pil-peringatan">Penting</span>
            <span v-if="!n.dibaca_pada" class="sr-only">Belum dibaca: </span>{{ n.judul }}
          </span>
          <span v-if="n.isi" class="ket">{{ n.isi }}</span>
          <time class="ket" :datetime="n.dibuat_pada">{{ formatTanggalWaktu(n.dibuat_pada) }}</time>
        </div>
        <button v-if="n.tautan" type="button" class="tombol" :aria-label="`Buka: ${n.judul}`" @click="buka(n)">Buka</button>
        <button v-else-if="!n.dibaca_pada" type="button" class="tombol" :aria-label="`Tandai dibaca: ${n.judul}`" @click="buka(n)">Tandai dibaca</button>
      </li>
    </ul>
    <div v-if="kueri.hasNextPage.value" class="muat-lagi">
      <button type="button" class="tombol" :disabled="kueri.isFetchingNextPage.value" @click="kueri.fetchNextPage()">
        {{ kueri.isFetchingNextPage.value ? 'Memuat…' : 'Muat lagi' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.alat {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}
.keadaan {
  margin: 0;
  padding: var(--space-6) 0;
  text-align: center;
  color: var(--muted);
}
.daftar {
  display: grid;
  margin: 0;
  padding: 0;
  list-style: none;
}
.daftar li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-3);
  border-bottom: 1px solid var(--border);
}
.daftar li:not(.dibaca) {
  background: var(--brand-25);
}
.daftar li.kritis {
  box-shadow: inset 3px 0 0 var(--danger);
}
.isi {
  display: grid;
  gap: 2px;
  min-width: 0;
}
.judul {
  font-weight: var(--font-weight-semibold);
}
.dibaca .judul {
  font-weight: var(--font-weight-regular, 400);
}
.ket {
  color: var(--muted);
  font-size: 12.5px;
}
.muat-lagi {
  display: flex;
  justify-content: center;
  padding-top: var(--space-3);
}
</style>
