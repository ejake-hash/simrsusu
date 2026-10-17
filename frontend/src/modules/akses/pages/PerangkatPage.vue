<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import Sakelar from '@/design-system/components/Sakelar.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { daftarPerangkatSaya, type StatusDaftar } from '../apiPerangkat'
import DaftarPerangkat from '../components/perangkat/DaftarPerangkat.vue'
import DialogCabutPerangkat from '../components/perangkat/DialogCabutPerangkat.vue'
import { KUNCI_PERANGKAT } from '../perangkat'
import { pesanError } from '../pengaturan'
import type { PerangkatTepercaya } from '../tipePerangkat'

/**
 * Menu akun → Perangkat tepercaya (tiket 1.13): perangkat yang tidak lagi diminta kode MFA saat masuk dari jaringan RS.
 * Pemilik akun mencabutnya tanpa alasan. Tanpa izin khusus (/saya/perangkat).
 */
const qc = useQueryClient()
const riwayat = ref(false)
const status = computed<StatusDaftar>(() => (riwayat.value ? 'semua' : 'aktif'))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_PERANGKAT.saya(status.value)),
  queryFn: ({ pageParam }) => daftarPerangkatSaya(status.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const daftar = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])

const dialog = ref(false)
const dipilih = ref<PerangkatTepercaya | null>(null)
const pesan = ref<string | null>(null)
function cabut(p: PerangkatTepercaya) {
  dipilih.value = p
  dialog.value = true
}
function dicabut(p: PerangkatTepercaya) {
  pesan.value = `${p.nama_perangkat} dicabut. Masuk berikutnya dari perangkat itu diminta kode MFA lagi.`
  void qc.invalidateQueries({ queryKey: ['perangkat', 'saya'] })
}
</script>

<template>
  <div class="halaman">
    <header class="kepala">
      <h1>Perangkat tepercaya</h1>
      <p>Peramban yang Anda percayai saat verifikasi dua langkah. Dari jaringan RS, perangkat ini tidak diminta kode MFA sampai masa berlakunya habis. Dari luar jaringan RS kode tetap diminta.</p>
    </header>

    <section class="kartu" aria-labelledby="judul-perangkat">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-perangkat">Perangkat Anda</h2>
          <p>Cabut perangkat yang hilang, dipinjam orang lain, atau tidak dipakai lagi.</p>
        </div>
        <Sakelar v-model="riwayat" label="Tampilkan riwayat" />
      </div>
      <div class="kartu-isi">
        <p v-if="pesan" class="catatan catatan-info catatan-atas" role="status">
          <Ikon nama="centang" :ukuran="16" />
          <span>{{ pesan }}</span>
        </p>
        <p v-if="kueri.isPending.value" role="status" class="keadaan">Memuat perangkat…</p>
        <div v-else-if="kueri.isError.value && !daftar.length" role="alert">
          <p class="catatan catatan-bahaya">{{ pesanError(kueri.error.value) }}</p>
          <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
        </div>
        <DaftarPerangkat
          v-else
          :daftar="daftar"
          :ada-lagi="!!kueri.hasNextPage.value"
          :memuat-lagi="kueri.isFetchingNextPage.value"
          boleh-cabut
          :kosong="riwayat ? 'Belum pernah ada perangkat tepercaya.' : 'Belum ada perangkat tepercaya yang aktif. Centang “Percayai perangkat ini” saat memasukkan kode MFA di jaringan RS.'"
          @muat-lagi="kueri.fetchNextPage()"
          @cabut="cabut"
        />
      </div>
    </section>
    <DialogCabutPerangkat v-model:open="dialog" :perangkat="dipilih" @selesai="dicabut" />
  </div>
</template>

<style scoped>
.kepala {
  margin-bottom: var(--space-4);
}
.kartu {
  max-width: 880px;
}
.keadaan {
  margin: 0;
  color: var(--muted);
}
.catatan-atas {
  margin: 0 0 var(--space-3);
}
</style>
