<script setup lang="ts">
import { useInfiniteQuery } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { refDebounced } from '@/shared/composables/refDebounced'
import { ambilDaftarPeran } from '../../apiPengaturan'
import { KUNCI, pesanError } from '../../pengaturan'
import type { FilterPeran } from '../../tipePengaturan'

/** Daftar peran (cari, status, jumlah akun, penanda bawaan/MFA/sensitif) dengan paginasi kursor "Muat lagi". */
const terpilih = defineModel<string | null>({ required: true })
defineEmits<{ buat: [] }>()

const cari = ref('')
const cariTunda = refDebounced(cari, 300)
const status = ref<FilterPeran['status'] | ''>('aktif')
const filter = computed<FilterPeran>(() => ({ cari: cariTunda.value.trim() || undefined, status: status.value || undefined }))

const kueri = useInfiniteQuery({
  queryKey: computed(() => [...KUNCI.daftarPeran, filter.value]),
  queryFn: ({ pageParam }) => ambilDaftarPeran(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const daftar = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
</script>

<template>
  <section class="kartu daftar" aria-labelledby="judul-daftar-peran">
    <div class="kartu-kepala">
      <h2 id="judul-daftar-peran">Peran</h2>
      <button v-can="'pengaturan.peran.input'" type="button" class="tombol" @click="$emit('buat')"><Ikon nama="tambah" :ukuran="16" />Buat peran</button>
    </div>
    <div class="filter">
      <label class="sr-only" for="cari-peran">Cari peran</label>
      <input id="cari-peran" v-model="cari" type="search" class="isian" placeholder="Cari nama atau kode" />
      <label class="sr-only" for="status-peran">Status peran</label>
      <select id="status-peran" v-model="status" class="isian">
        <option value="aktif">Aktif</option>
        <option value="nonaktif">Nonaktif</option>
        <option value="">Semua status</option>
      </select>
    </div>

    <p v-if="kueri.isPending.value" class="keadaan" role="status">Memuat peran…</p>
    <div v-else-if="kueri.isError.value" class="keadaan" role="alert">
      <p>{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <p v-else-if="daftar.length === 0" class="keadaan">Tidak ada peran yang cocok.</p>
    <ul v-else class="item-daftar">
      <li v-for="p in daftar" :key="p.id">
        <button type="button" class="item" :class="{ aktif: terpilih === p.id }" :aria-current="terpilih === p.id ? 'true' : undefined" @click="terpilih = p.id">
          <span class="baris-1">
            <b>{{ p.nama }}</b>
            <span class="jumlah">{{ p.jumlah_akun }} akun</span>
          </span>
          <span class="baris-2">
            <code>{{ p.kode }}</code>
            <span v-if="p.bawaan_sistem" class="pil pil-merek">Bawaan</span>
            <span v-if="p.wajib_mfa" class="pil pil-info">MFA</span>
            <span v-if="p.memuat_izin_sensitif" class="pil pil-peringatan"><Ikon nama="gembok" :ukuran="12" /><span class="sr-only">Memuat izin </span>sensitif</span>
            <span v-if="p.status === 'nonaktif'" class="pil pil-netral">Nonaktif</span>
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
  display: grid;
  grid-template-columns: minmax(0, 1fr) 108px;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--border);
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
.baris-1 {
  display: flex;
  justify-content: space-between;
  gap: var(--space-2);
}
.jumlah {
  flex: none;
  font-size: 12px;
  color: var(--muted);
}
.baris-2 {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
}
.baris-2 code {
  margin-right: 2px;
  font-family: var(--font-mono);
  font-size: 11.5px;
  color: var(--muted);
}
.muat-lagi {
  padding: var(--space-2) var(--space-4) var(--space-3);
}
</style>
