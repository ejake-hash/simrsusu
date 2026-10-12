<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Tabs from '@/design-system/components/Tabs.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { boleh } from '@/shared/composables/usePermission'
import { useKelasLayar } from '@/shared/composables/useKelasLayar'
import DaftarPersetujuan from '../components/persetujuan/DaftarPersetujuan.vue'
import RincianPersetujuan from '../components/persetujuan/RincianPersetujuan.vue'
import TabPenyetujuIzin from '../components/persetujuan/TabPenyetujuIzin.vue'
import TabPenyetujuLabel from '../components/persetujuan/TabPenyetujuLabel.vue'
import TabPermintaan from '../components/persetujuan/TabPermintaan.vue'
import type { TabPersetujuan } from '../tipePersetujuan'

/**
 * Pengaturan → Persetujuan akses (PRD 9.3 antrean persetujuan dan permintaan akses, tiket 1.2d). Sub-menu ini diberikan
 * lewat peran (bawaan superadmin; admin memberikannya ke peran pemilik data). Tab dan pengajuan terpilih di URL
 * (?tab=&id=) agar bisa dibagikan dan bertahan saat muat ulang. Permintaan akses dan penyetuju izin hanya untuk
 * pemegang izin pengelola akun/peran.
 */
const route = useRoute()
const router = useRouter()
const kelas = useKelasLayar()
/** Ponsel: keputusan persetujuan boleh (PRD 5.5), tetapi pemberian akses (permintaan, penyetuju) tidak dari layar sempit. */
const ponsel = computed(() => kelas.value === 'ponsel')

const daftarTab = computed(() => [
  { nilai: 'perlu_saya_putuskan', label: 'Perlu saya putuskan' },
  { nilai: 'saya_ajukan', label: 'Yang saya ajukan' },
  ...(boleh('pengaturan.akun.lihat') && !ponsel.value ? [{ nilai: 'permintaan', label: 'Permintaan akses' }] : []),
  ...(boleh('pengaturan.peran.lihat') && !ponsel.value ? [{ nilai: 'penyetuju', label: 'Penyetuju izin' }] : []),
  // Label sensitivitas (1.5): daftar label butuh pengaturan.akun.lihat, pilihan peran butuh pengaturan.peran.lihat.
  ...(boleh('pengaturan.peran.lihat') && boleh('pengaturan.akun.lihat') && !ponsel.value ? [{ nilai: 'penyetuju-label', label: 'Penyetuju label' }] : []),
])
const tab = computed({
  get: () => {
    const t = typeof route.query.tab === 'string' ? route.query.tab : ''
    return daftarTab.value.some((d) => d.nilai === t) ? t : 'perlu_saya_putuskan'
  },
  set: (t: string) => void router.replace({ query: { tab: t === 'perlu_saya_putuskan' ? undefined : t } }),
})
const terpilih = computed({
  get: () => (typeof route.query.id === 'string' ? route.query.id : null),
  set: (id: string | null) => void router.replace({ query: { ...route.query, id: id ?? undefined } }),
})
// Layar sempit: rincian ada di bawah daftar, jadi digulir ke tampilan dan judulnya difokuskan saat pengajuan dipilih.
const fokusRincian = ref(false)
watch(terpilih, async (id) => {
  fokusRincian.value = false
  if (!id || !window.matchMedia?.('(max-width: 1023.98px)').matches) return
  fokusRincian.value = true
  await nextTick()
  // Bagian rinci sudah terpasang walau datanya masih dimuat (judul baru ada sesudahnya).
  document.querySelector('section[aria-labelledby="judul-rincian-persetujuan"]')?.scrollIntoView?.({ block: 'start', behavior: 'smooth' })
})
</script>

<template>
  <HalamanModul
    izin="pengaturan.persetujuan.lihat"
    judul="Persetujuan akses"
    uraian="Pemberian akses berisiko (izin sensitif, peran superadmin) baru berlaku setelah disetujui penyetuju yang ditunjuk. Pengaju tidak bisa menyetujui pengajuannya sendiri."
  >
    <Tabs v-model="tab" label="Persetujuan akses" :daftar="daftarTab">
      <template v-for="t in ['perlu_saya_putuskan', 'saya_ajukan'] as TabPersetujuan[]" :key="t" #[t]>
        <div class="tata">
          <DaftarPersetujuan v-model="terpilih" :tab="t" />
          <RincianPersetujuan v-if="terpilih" :id="terpilih" :key="terpilih" :fokus-saat-muat="fokusRincian" @tutup="terpilih = null" />
          <section v-else class="kartu kosong">
            <h2>Pilih pengajuan</h2>
            <p>Pilih pengajuan di daftar untuk melihat siapa mengajukan apa, dasarnya, dan dampaknya.</p>
          </section>
        </div>
      </template>
      <template #permintaan><TabPermintaan /></template>
      <template #penyetuju><TabPenyetujuIzin /></template>
      <template #penyetuju-label><TabPenyetujuLabel /></template>
    </Tabs>
    <p v-if="ponsel && (boleh('pengaturan.akun.lihat') || boleh('pengaturan.peran.lihat'))" class="catatan catatan-netral ponsel">
      Permintaan akses, penyetuju izin, dan penyetuju label dikelola dari tablet atau komputer, agar perubahan akses tidak dibuat dari layar sempit.
    </p>
  </HalamanModul>
</template>

<style scoped>
.tata {
  display: grid;
  grid-template-columns: minmax(280px, 380px) minmax(0, 1fr);
  align-items: start;
  gap: var(--space-4);
}
.ponsel {
  margin: var(--space-4) 0 0;
}
.kosong {
  padding: var(--space-8) var(--space-6);
  text-align: center;
  color: var(--muted);
}
.kosong h2 {
  margin: 0 0 var(--space-1);
  font-size: 15px;
  color: var(--text);
}
.kosong p {
  margin: 0;
}
@media (max-width: 1023.98px) {
  .tata {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
