<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { ambilPreferensi, aturPreferensi } from '../api'
import { KUNCI_NOTIF } from '../notifikasi'
import type { PreferensiNotifikasi } from '../tipe'

/**
 * Preferensi kanal per jenis notifikasi (FR-53-09, K2). In-app selalu menyala. Kanal yang `dapat_diubah = false`
 * (jenis kritis, kanal belum tersedia, atau akun tanpa email) ditampilkan terkunci dengan alasannya. Simpan hanya
 * mengirim yang berubah.
 */
const qc = useQueryClient()
const kueri = useQuery({ queryKey: KUNCI_NOTIF.preferensi, queryFn: ambilPreferensi })
const draf = ref<Record<string, boolean>>({})
watch(
  () => kueri.data.value,
  (d) => (draf.value = Object.fromEntries((d ?? []).map((p) => [p.jenis, p.email.aktif]))),
  { immediate: true },
)
const berubah = computed(() => (kueri.data.value ?? []).filter((p) => p.email.dapat_diubah && draf.value[p.jenis] !== p.email.aktif))
const sibuk = ref(false)
const error = ref<string | null>(null)

const alasanTerkunci = (p: PreferensiNotifikasi, kanal: 'email' | 'pesan_singkat') =>
  p.kritis ? 'Kritis: selalu dikirim' : kanal === 'pesan_singkat' ? 'Belum tersedia' : 'Akun tanpa alamat email'

async function simpan() {
  sibuk.value = true
  error.value = null
  try {
    const hasil = await aturPreferensi(berubah.value.map((p) => ({ jenis: p.jenis, email: draf.value[p.jenis] })))
    qc.setQueryData(KUNCI_NOTIF.preferensi, hasil)
    tampilkanToast('Preferensi notifikasi disimpan.')
  } catch (e) {
    error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <div>
    <p class="ket atas">Notifikasi di aplikasi selalu dikirim. Notifikasi kritis juga selalu dikirim lewat email bila akun punya alamat email.</p>
    <p v-if="kueri.isPending.value" class="keadaan" role="status">Memuat preferensi…</p>
    <div v-else-if="kueri.isError.value" class="keadaan" role="alert">
      <p>{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <form v-else novalidate @submit.prevent="simpan">
      <div class="tbl-wrap">
        <table class="tbl">
          <caption class="sr-only">Preferensi kanal notifikasi</caption>
          <thead>
            <tr>
              <th scope="col">Jenis</th>
              <th scope="col">Aplikasi</th>
              <th scope="col">Email</th>
              <th scope="col">Pesan singkat</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in kueri.data.value ?? []" :key="p.jenis">
              <th scope="row">
                {{ p.nama }}
                <span v-if="p.kritis" class="pil pil-bahaya">Kritis</span>
              </th>
              <td><span class="ket">Selalu</span></td>
              <td>
                <label class="sakelar">
                  <input v-model="draf[p.jenis]" type="checkbox" :disabled="!p.email.dapat_diubah" :aria-label="`Email untuk ${p.nama}`" />
                  <span v-if="!p.email.dapat_diubah" class="ket">{{ alasanTerkunci(p, 'email') }}</span>
                </label>
              </td>
              <td>
                <label class="sakelar">
                  <input type="checkbox" :checked="p.pesan_singkat.aktif" :disabled="!p.pesan_singkat.dapat_diubah" :aria-label="`Pesan singkat untuk ${p.nama}`" />
                  <span v-if="!p.pesan_singkat.dapat_diubah" class="ket">{{ alasanTerkunci(p, 'pesan_singkat') }}</span>
                </label>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <div class="aksi">
        <Tombol type="submit" varian="utama" :sibuk="sibuk" :disabled="!berubah.length">{{ sibuk ? 'Menyimpan…' : 'Simpan preferensi' }}</Tombol>
      </div>
    </form>
  </div>
</template>

<style scoped>
.atas {
  margin: 0 0 var(--space-3);
}
.keadaan {
  margin: 0;
  padding: var(--space-6) 0;
  text-align: center;
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
.tbl th,
.tbl td {
  padding: var(--space-2) var(--space-3);
  border-bottom: 1px solid var(--border);
  text-align: left;
  vertical-align: middle;
}
.tbl thead th {
  color: var(--muted);
  font-size: 12px;
  font-weight: var(--font-weight-semibold);
}
.tbl tbody th {
  font-weight: var(--font-weight-semibold);
}
.sakelar {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 32px;
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .sakelar {
    min-height: 44px;
  }
}
.ket {
  color: var(--muted);
  font-size: 12.5px;
}
.aksi {
  display: flex;
  justify-content: flex-end;
  padding-top: var(--space-3);
}
</style>
