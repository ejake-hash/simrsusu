<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { boleh } from '@/shared/composables/usePermission'
import { ambilPenyiapan } from '../api'
import { KUNCI_PENYIAPAN } from '../penyiapan'

/**
 * Indikator Beranda (tiket 1.8f fe 1): "Instalasi belum bisa melayani pasien" bagi pemegang
 * pengaturan.penyiapan.lihat, dengan jumlah langkah tuntas dan tautan ke Penyiapan. Tanpa izin: tidak memanggil server.
 */
const bolehLihat = computed(() => boleh('pengaturan.penyiapan.lihat'))
const kueri = useQuery({ queryKey: KUNCI_PENYIAPAN.status, queryFn: ambilPenyiapan, enabled: bolehLihat, staleTime: 60_000 })
const data = computed(() => (bolehLihat.value ? (kueri.data.value ?? null) : null))
const tuntas = computed(() => data.value?.langkah.filter((l) => l.status === 'selesai' || l.status === 'dilewati').length ?? 0)
const total = computed(() => data.value?.langkah.filter((l) => l.status !== 'menunggu_fase_2').length ?? 0)
</script>

<template>
  <section v-if="data && !data.siap_melayani" class="kartu pita" aria-labelledby="judul-pita-penyiapan">
    <div class="kartu-isi isi">
      <Ikon nama="peringatan" :ukuran="20" />
      <div class="teks">
        <h2 id="judul-pita-penyiapan">Instalasi belum bisa melayani pasien</h2>
        <p>{{ tuntas }} dari {{ total }} langkah penyiapan tuntas. Langkah 1–6 wajib selesai sebelum pendaftaran pasien dibuka.</p>
      </div>
      <RouterLink to="/pengaturan/penyiapan" class="tombol">Buka penyiapan</RouterLink>
    </div>
  </section>
</template>

<style scoped>
.pita {
  margin-bottom: var(--space-4);
  box-shadow: inset 3px 0 0 var(--warn);
}
.isi {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-3);
  color: var(--warn);
}
.teks {
  flex: 1 1 260px;
  min-width: 0;
  color: var(--text);
}
.teks h2 {
  margin: 0;
  font-size: 15px;
}
.teks p {
  margin: 2px 0 0;
  color: var(--muted);
  font-size: 13px;
}
</style>
