<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import SidebarMenu from '@/layouts/SidebarMenu.vue'
import { ambilMenuAkun } from '../../apiPengaturan'
import { labelIzin, type IndeksMatriks } from '../../matriks'
import { KUNCI, pesanError } from '../../pengaturan'
import type { AkunRinci } from '../../tipePengaturan'

/**
 * Lihat sebagai (FR-09-08): sidebar dan izin aksi akun lain dari GET /akun/{id}/menu, dihitung server dengan
 * AccessEvaluator yang sama dengan /saya/menu. Hanya pratinjau: tanpa tautan, tidak membuat sesi atas nama akun itu.
 */
const props = defineProps<{ akun: AkunRinci; indeks: IndeksMatriks | null }>()

// Setiap permintaan tercatat audit akses.lihat_sebagai: dimuat sekali per buka tab, tidak diulang saat tab kembali fokus.
const kueri = useQuery({
  queryKey: computed(() => KUNCI.menuAkun(props.akun.id)),
  queryFn: () => ambilMenuAkun(props.akun.id),
  staleTime: 5 * 60_000,
  refetchOnWindowFocus: false,
})
// Urut sesuai pohon menu (seperti matriks), bukan abjad kode.
const izin = computed(() => {
  const daftar = kueri.data.value?.izin ?? []
  if (!props.indeks) return daftar
  const posisi = new Map([...props.indeks.perKode.keys()].map((k, i) => [k, i]))
  return [...daftar].sort((a, b) => (posisi.get(a) ?? Infinity) - (posisi.get(b) ?? Infinity))
})
</script>

<template>
  <div>
    <div class="kepala-tab">
      <h3>Lihat sebagai {{ akun.nama }}</h3>
    </div>
    <p class="catatan catatan-info pembuka">Pratinjau menu dan tombol yang akan dilihat akun ini. Tidak membuka sesi atas nama akun ini; tercatat di jejak audit.</p>
    <p v-if="kueri.isPending.value" class="kosong" role="status">Memuat menu akun…</p>
    <div v-else-if="kueri.isError.value" role="alert">
      <p class="kosong">{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <div v-else-if="kueri.data.value" class="pratinjau">
      <div class="sidebar-pratinjau">
        <SidebarMenu :grup="kueri.data.value.grup" kelas="desktop" pratinjau :label="`Pratinjau menu ${akun.nama}`" />
      </div>
      <section class="izin-aksi" aria-labelledby="judul-izin-aksi">
        <h4 id="judul-izin-aksi">Izin aksi ({{ izin.length }})</h4>
        <ul v-if="izin.length">
          <li v-for="k in izin" :key="k">{{ indeks ? labelIzin(indeks, k) : k }}</li>
        </ul>
        <p v-else class="kosong">Tidak ada izin aksi.</p>
      </section>
    </div>
  </div>
</template>

<style scoped src="./tab-akun.css"></style>
<style scoped>
.pembuka {
  margin: 0 0 var(--space-3);
}
.pratinjau {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: var(--space-3);
}
.sidebar-pratinjau {
  display: flex;
  max-height: 480px;
  overflow-y: auto;
  border: 1px solid var(--border);
  border-radius: 10px;
}
.izin-aksi h4 {
  margin: 0 0 6px;
  font-size: 13px;
}
.izin-aksi ul {
  max-height: 440px;
  overflow-y: auto;
  margin: 0;
  padding-left: 18px;
  font-size: 12.5px;
  color: var(--muted);
}
</style>
