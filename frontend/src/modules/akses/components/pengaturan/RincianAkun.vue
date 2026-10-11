<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import Tabs from '@/design-system/components/Tabs.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { ambilAkun } from '../../apiPengaturan'
import type { IndeksMatriks } from '../../matriks'
import { KUNCI, pesanError } from '../../pengaturan'
import TabLihatSebagai from './TabLihatSebagai.vue'
import TabMfa from './TabMfa.vue'
import TabPenugasan from './TabPenugasan.vue'
import TabPerAkun from './TabPerAkun.vue'

/**
 * Rincian akun: Penugasan, Per akun, Lihat sebagai, MFA. Akun sendiri (diri_sendiri) tidak punya tombol ubah
 * sama sekali (PRD 9.2.4); server tetap menolak dengan 403 diri_sendiri.
 */
const props = defineProps<{ akunId: string; indeks: IndeksMatriks | null }>()

const qc = useQueryClient()
const kueri = useQuery({ queryKey: computed(() => KUNCI.akun(props.akunId)), queryFn: () => ambilAkun(props.akunId) })
const akun = computed(() => kueri.data.value)

const TAB = [
  { nilai: 'penugasan', label: 'Penugasan' },
  { nilai: 'per-akun', label: 'Per akun' },
  { nilai: 'lihat-sebagai', label: 'Lihat sebagai' },
  { nilai: 'mfa', label: 'MFA' },
]
const tab = ref('penugasan')
const pesan = ref<string | null>(null)
watch(
  () => props.akunId,
  () => {
    pesan.value = null
  },
)

const terkunci = computed(() => akun.value?.terkunci_sampai && new Date(akun.value.terkunci_sampai) > new Date())
const superadmin = computed(() => akun.value?.mfa.sumber_wajib.some((s) => s.jenis === 'superadmin') ?? false)

function berubah(teks: string) {
  pesan.value = teks
  void qc.invalidateQueries({ queryKey: KUNCI.akun(props.akunId) })
  void qc.invalidateQueries({ queryKey: KUNCI.daftarAkun, exact: false, refetchType: 'active' })
  void qc.invalidateQueries({ queryKey: KUNCI.daftarPeran })
}
</script>

<template>
  <section class="kartu rincian" aria-labelledby="judul-rincian-akun">
    <div v-if="kueri.isPending.value" class="kartu-isi keadaan" role="status">Memuat akun…</div>
    <div v-else-if="kueri.isError.value" class="kartu-isi" role="alert">
      <p class="catatan catatan-bahaya">{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <template v-else-if="akun">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-rincian-akun">{{ akun.nama }}</h2>
          <p>{{ akun.nama_pengguna }} · masuk terakhir {{ formatTanggalWaktu(akun.login_terakhir_pada) ?? 'belum pernah' }}</p>
        </div>
        <div class="pil-baris">
          <span v-if="akun.diri_sendiri" class="pil pil-merek">Akun Anda</span>
          <span class="pil" :class="akun.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ akun.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</span>
          <span v-if="terkunci" class="pil pil-bahaya"><Ikon nama="gembok" :ukuran="12" />Terkunci</span>
        </div>
      </div>
      <div class="kartu-isi">
        <p v-if="akun.diri_sendiri" class="catatan catatan-netral catatan-atas">
          <Ikon nama="info" :ukuran="16" />
          <span>Perubahan akun sendiri dilakukan superadmin lain.</span>
        </p>
        <p v-else-if="akun.alasan_terkunci === 'akun_superadmin'" class="catatan catatan-netral catatan-atas">
          <Ikon nama="gembok" :ukuran="16" />
          <span>Akses akun superadmin hanya diubah oleh superadmin lain.</span>
        </p>
        <p v-if="superadmin" class="catatan catatan-info catatan-atas">
          <Ikon nama="info" :ukuran="16" />
          <span>Superadmin mengelola menu, peran, dan akun; tidak membuka rekam medis atau angka rupiah tanpa peran klinis/keuangan tersendiri.</span>
        </p>
        <p v-if="pesan" class="catatan catatan-info catatan-atas" role="status">
          <Ikon nama="centang" :ukuran="16" />
          <span>{{ pesan }}</span>
        </p>

        <Tabs v-model="tab" label="Rincian akun" :daftar="TAB">
          <template #penugasan><TabPenugasan :akun="akun" @berubah="berubah" /></template>
          <template #per-akun><TabPerAkun :akun="akun" :indeks="indeks" @berubah="berubah" /></template>
          <template #lihat-sebagai><TabLihatSebagai :akun="akun" :indeks="indeks" /></template>
          <template #mfa><TabMfa :akun="akun" @berubah="berubah" /></template>
        </Tabs>
      </div>
    </template>
  </section>
</template>

<style scoped>
.rincian {
  min-width: 0;
}
.keadaan {
  color: var(--muted);
}
.pil-baris {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
}
.catatan-atas {
  margin: 0 0 var(--space-3);
}
</style>
