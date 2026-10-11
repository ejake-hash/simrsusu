<script setup lang="ts">
import { useInfiniteQuery } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { refDebounced } from '@/shared/composables/refDebounced'
import { formatTanggal } from '@/shared/utils/waktu'
import { ambilDaftarAkun } from '../../apiPengaturan'
import { KUNCI, pesanError, usePeranAktif } from '../../pengaturan'
import type { AkunRingkas, FilterAkun } from '../../tipePengaturan'

/**
 * Daftar akun (PRD 9.3): cari, filter status/peran/MFA, paginasi kursor "Muat lagi" (PRD 14.1.1). Tanpa NIP,
 * email, atau angka ringkasan tiruan. Kolom diringkas di tablet: peran dan pengecualian tetap, MFA jadi pil.
 */
const terpilih = defineModel<string | null>({ required: true })

const cari = ref('')
const cariTunda = refDebounced(cari, 300)
const status = ref<FilterAkun['status'] | ''>('')
const peranId = ref('')
const mfa = ref<FilterAkun['mfa'] | ''>('')
const filter = computed<FilterAkun>(() => ({
  cari: cariTunda.value.trim() || undefined,
  status: status.value || undefined,
  peran_id: peranId.value || undefined,
  mfa: mfa.value || undefined,
}))
const peranAktif = usePeranAktif()

const kueri = useInfiniteQuery({
  queryKey: computed(() => [...KUNCI.daftarAkun, filter.value]),
  queryFn: ({ pageParam }) => ambilDaftarAkun(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const daftar = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])

const terkunci = (a: AkunRingkas) => a.terkunci_sampai !== null && new Date(a.terkunci_sampai) > new Date()
</script>

<template>
  <section class="kartu daftar" aria-labelledby="judul-daftar-akun">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-daftar-akun">Akun pengguna</h2>
        <p>Peran yang berlaku, pengecualian per akun, dan MFA</p>
      </div>
    </div>
    <div class="filter">
      <label class="cari">
        <span class="sr-only">Cari akun</span>
        <input v-model="cari" type="search" class="isian" placeholder="Cari nama atau nama pengguna" />
      </label>
      <label>
        <span class="sr-only">Status akun</span>
        <select v-model="status" class="isian">
          <option value="">Semua status</option>
          <option value="aktif">Aktif</option>
          <option value="nonaktif">Nonaktif</option>
          <option value="terkunci">Terkunci</option>
        </select>
      </label>
      <label>
        <span class="sr-only">Peran</span>
        <select v-model="peranId" class="isian">
          <option value="">Semua peran</option>
          <option v-for="p in peranAktif.data.value ?? []" :key="p.id" :value="p.id">{{ p.nama }}</option>
        </select>
      </label>
      <label>
        <span class="sr-only">MFA</span>
        <select v-model="mfa" class="isian">
          <option value="">MFA: semua</option>
          <option value="aktif">MFA terdaftar</option>
          <option value="belum">MFA belum</option>
        </select>
      </label>
    </div>

    <p v-if="kueri.isPending.value" class="keadaan" role="status">Memuat akun…</p>
    <div v-else-if="kueri.isError.value" class="keadaan" role="alert">
      <p>{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <p v-else-if="daftar.length === 0" class="keadaan">Tidak ada akun yang cocok dengan filter.</p>
    <div v-else class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th scope="col">Akun</th>
            <th scope="col">Peran</th>
            <th scope="col" class="kol-kecil">Per akun</th>
            <th scope="col" class="kol-kecil">MFA</th>
            <th scope="col" class="kol-kecil">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in daftar" :key="a.id" :class="{ aktif: terpilih === a.id }">
            <td>
              <button type="button" class="pilih" :aria-current="terpilih === a.id ? 'true' : undefined" @click="terpilih = a.id">
                <b>{{ a.nama }}</b>
                <small>{{ a.nama_pengguna }}</small>
              </button>
            </td>
            <td>
              <template v-if="a.peran.length">
                <span v-for="p in a.peran" :key="p.id" class="peran">
                  {{ p.nama }}
                  <small>{{ p.berlaku_sampai ? `s.d. ${formatTanggal(p.berlaku_sampai)}` : 'tidak dibatasi' }}</small>
                </span>
              </template>
              <span v-else class="redup">Belum ada peran</span>
            </td>
            <td class="kol-kecil">
              <span v-if="a.pengecualian.tambah" class="pil pil-ok">+{{ a.pengecualian.tambah }}<span class="sr-only"> ditambah</span></span>
              <span v-if="a.pengecualian.cabut" class="pil pil-bahaya">−{{ a.pengecualian.cabut }}<span class="sr-only"> dicabut</span></span>
              <span v-if="!a.pengecualian.tambah && !a.pengecualian.cabut" class="redup">—</span>
            </td>
            <td class="kol-kecil">
              <span class="pil" :class="a.mfa_aktif ? 'pil-ok' : 'pil-netral'">{{ a.mfa_aktif ? 'Terdaftar' : 'Belum' }}</span>
            </td>
            <td class="kol-kecil">
              <span v-if="terkunci(a)" class="pil pil-bahaya"><Ikon nama="gembok" :ukuran="12" />Terkunci</span>
              <span v-else class="pil" :class="a.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ a.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
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
  grid-template-columns: minmax(180px, 2fr) repeat(3, minmax(120px, 1fr));
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--border);
}
.keadaan {
  margin: 0;
  padding: var(--space-4);
  color: var(--muted);
}
.tbl-wrap {
  overflow-x: auto;
}
.tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--text-table);
}
.tbl th {
  padding: 10px var(--space-3);
  border-bottom: 1px solid var(--border);
  background: var(--bg);
  color: var(--muted);
  font-size: 12px;
  font-weight: var(--font-weight-semibold);
  text-align: left;
  white-space: nowrap;
}
.tbl td {
  padding: var(--space-2) var(--space-3);
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}
.tbl tr.aktif td {
  background: var(--brand-25);
}
.kol-kecil {
  width: 1%;
  white-space: nowrap;
}
.pilih {
  display: grid;
  gap: 2px;
  width: 100%;
  min-height: 40px;
  padding: 2px 0;
  border: 0;
  background: none;
  color: var(--text);
  font: inherit;
  text-align: left;
  cursor: pointer;
}
.pilih small,
.peran small,
.redup {
  font-size: 12px;
  color: var(--muted);
}
.pilih:hover b {
  color: var(--brand-600);
  text-decoration: underline;
}
.pilih:focus-visible {
  outline: none;
  box-shadow: var(--ring);
  border-radius: 4px;
}
.peran {
  display: grid;
  margin-bottom: 2px;
}
.muat-lagi {
  padding: var(--space-2) var(--space-4) var(--space-3);
}

@media (max-width: 1279.98px) {
  .filter {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .pilih {
    min-height: 44px;
  }
}
</style>
