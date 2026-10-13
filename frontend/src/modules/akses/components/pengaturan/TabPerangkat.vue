<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import Sakelar from '@/design-system/components/Sakelar.vue'
import { boleh } from '@/shared/composables/usePermission'
import { daftarPerangkatAkun, type StatusDaftar } from '../../apiPerangkat'
import { KUNCI_PERANGKAT } from '../../perangkat'
import { bolehUbahAkun, pesanError } from '../../pengaturan'
import type { AkunRinci } from '../../tipePengaturan'
import type { PerangkatTepercaya } from '../../tipePerangkat'
import DaftarPerangkat from '../perangkat/DaftarPerangkat.vue'
import DialogCabutPerangkat from '../perangkat/DialogCabutPerangkat.vue'

/**
 * Tab Perangkat di rincian akun (tiket 1.13): perangkat tepercaya akun itu. Cabut beralasan hanya bila akun boleh
 * diubah penyunting (bukan diri sendiri, bukan superadmin oleh non-superadmin) dan memegang pengaturan.akun.perbaiki.
 */
const props = defineProps<{ akun: AkunRinci }>()
const emit = defineEmits<{ berubah: [pesan: string] }>()

const qc = useQueryClient()
const riwayat = ref(false)
const status = computed<StatusDaftar>(() => (riwayat.value ? 'semua' : 'aktif'))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_PERANGKAT.akun(props.akun.id, status.value)),
  queryFn: ({ pageParam }) => daftarPerangkatAkun(props.akun.id, status.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const daftar = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const bolehCabut = computed(() => bolehUbahAkun(props.akun) && boleh('pengaturan.akun.perbaiki'))

const dialog = ref(false)
const dipilih = ref<PerangkatTepercaya | null>(null)
function cabut(p: PerangkatTepercaya) {
  dipilih.value = p
  dialog.value = true
}
function dicabut(p: PerangkatTepercaya) {
  void qc.invalidateQueries({ queryKey: ['perangkat', 'akun', props.akun.id] })
  emit('berubah', `Perangkat ${p.nama_perangkat} milik ${props.akun.nama_pengguna} dicabut.`)
}
</script>

<template>
  <div class="tab-perangkat">
    <div class="atas">
      <p class="uraian">Peramban yang tidak diminta kode MFA saat akun ini masuk dari jaringan RS.</p>
      <Sakelar v-model="riwayat" label="Tampilkan riwayat" />
    </div>
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
      :boleh-cabut="bolehCabut"
      :kosong="riwayat ? 'Akun ini belum pernah mempercayai perangkat.' : 'Tidak ada perangkat tepercaya yang aktif.'"
      @muat-lagi="kueri.fetchNextPage()"
      @cabut="cabut"
    />
    <DialogCabutPerangkat v-model:open="dialog" :perangkat="dipilih" :akun-id="akun.id" :nama-akun="akun.nama_pengguna" @selesai="dicabut" />
  </div>
</template>

<style scoped>
.atas {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2) var(--space-4);
  margin-bottom: var(--space-3);
}
.uraian,
.keadaan {
  margin: 0;
  color: var(--muted);
  font-size: 13px;
}
</style>
