<script setup lang="ts">
import { useInfiniteQuery } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { daftarPersetujuan } from '../../apiPersetujuan'
import { KUNCI, pesanError } from '../../pengaturan'
import { LABEL_STATUS_PERSETUJUAN, PIL_STATUS_PERSETUJUAN } from '../../persetujuan'
import type { StatusPersetujuan, TabPersetujuan } from '../../tipePersetujuan'

/**
 * Antrean persetujuan satu tab (GET /persetujuan-akses berkursor, terbaru dulu). "Perlu saya putuskan" hanya berisi yang
 * masih menunggu dan boleh diputuskan akun ini, jadi tanpa filter status; "Yang saya ajukan" punya filter status.
 */
const props = defineProps<{ tab: TabPersetujuan }>()
const terpilih = defineModel<string | null>({ required: true })

const status = ref<StatusPersetujuan | ''>('')
const statusKueri = computed(() => (props.tab === 'saya_ajukan' && status.value ? status.value : undefined))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI.daftarPersetujuan(props.tab, statusKueri.value)),
  queryFn: ({ pageParam }) => daftarPersetujuan(props.tab, statusKueri.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const daftar = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const judul = computed(() => (props.tab === 'perlu_saya_putuskan' ? 'Perlu saya putuskan' : 'Yang saya ajukan'))
const idJudul = `judul-daftar-${props.tab}`
</script>

<template>
  <section class="kartu daftar" :aria-labelledby="idJudul">
    <div class="kartu-kepala">
      <h2 :id="idJudul">{{ judul }}</h2>
    </div>
    <div v-if="tab === 'saya_ajukan'" class="filter">
      <label class="sr-only" :for="`status-${tab}`">Status pengajuan</label>
      <select :id="`status-${tab}`" v-model="status" class="isian">
        <option value="">Semua status</option>
        <option v-for="(label, s) in LABEL_STATUS_PERSETUJUAN" :key="s" :value="s">{{ label }}</option>
      </select>
    </div>

    <p v-if="kueri.isPending.value" class="keadaan" role="status">Memuat pengajuan…</p>
    <div v-else-if="kueri.isError.value" class="keadaan" role="alert">
      <p>{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <p v-else-if="daftar.length === 0" class="keadaan">
      {{ tab === 'perlu_saya_putuskan' ? 'Tidak ada pengajuan yang menunggu keputusan Anda.' : 'Belum ada pengajuan yang cocok.' }}
    </p>
    <ul v-else class="item-daftar">
      <li v-for="p in daftar" :key="p.id">
        <button type="button" class="item" :class="{ aktif: terpilih === p.id }" :aria-current="terpilih === p.id ? 'true' : undefined" @click="terpilih = p.id">
          <span class="ringkasan">{{ p.ringkasan }}</span>
          <span class="baris-2">
            <span class="pil" :class="PIL_STATUS_PERSETUJUAN[p.status]">{{ LABEL_STATUS_PERSETUJUAN[p.status] }}</span>
            <span v-if="p.jenis === 'peran_superadmin'" class="pil pil-merek">Superadmin</span>
            <span v-else class="pil pil-peringatan"><Ikon nama="gembok" :ukuran="12" />Sensitif</span>
            <span class="ket">{{ p.nomor }} · {{ formatTanggalWaktu(p.diajukan_pada) }}</span>
          </span>
        </button>
      </li>
    </ul>
    <div v-if="kueri.hasNextPage.value" class="muat-lagi">
      <button type="button" class="tombol" :disabled="kueri.isFetchingNextPage.value" @click="kueri.fetchNextPage()">
        {{ kueri.isFetchingNextPage.value ? 'Memuat…' : 'Muat lagi' }}
      </button>
    </div>
  </section>
</template>

<style scoped>
.daftar {
  min-width: 0;
}
.filter {
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--border);
}
.filter .isian {
  width: 100%;
}
.keadaan {
  margin: 0;
  padding: var(--space-4);
  color: var(--muted);
}
.item-daftar {
  list-style: none;
  margin: 0;
  padding: var(--space-1);
  max-height: min(70vh, 900px);
  overflow-y: auto;
}
.item {
  box-sizing: border-box;
  display: grid;
  gap: 4px;
  width: 100%;
  min-height: 44px;
  padding: var(--space-2) var(--space-3);
  border: 0;
  border-radius: var(--radius-input);
  background: none;
  color: var(--text);
  font: inherit;
  text-align: left;
  cursor: pointer;
}
.item:hover {
  background: var(--bg);
}
.item.aktif {
  background: var(--brand-25);
  box-shadow: inset 3px 0 0 var(--brand-600);
}
.item:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
.ringkasan {
  font-weight: var(--font-weight-medium);
}
.baris-2 {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
}
.ket {
  font-size: 12px;
  color: var(--muted);
}
.muat-lagi {
  padding: var(--space-2) var(--space-4) var(--space-3);
}
</style>
