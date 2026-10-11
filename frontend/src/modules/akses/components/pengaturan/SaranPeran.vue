<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { ambilSaranPeran } from '../../apiPersetujuan'

/**
 * Saran jadikan peran (FR-09-15, tiket 1.2d): pengecualian per akun yang sama dipakai ≥ 5 akun. Hanya tampil bila ada
 * saran; tidak mengubah apa pun (pembuatan peran tetap lewat Peran & izin). Gagal dimuat = disembunyikan.
 */
const kueri = useQuery({ queryKey: ['pengaturan', 'saran-peran'], queryFn: ambilSaranPeran, staleTime: 5 * 60_000 })
</script>

<template>
  <details v-if="kueri.data.value?.length" class="kartu saran">
    <summary>
      <Ikon nama="info" :ukuran="16" />
      <span>{{ kueri.data.value.length }} pengecualian yang sama dipakai banyak akun — pertimbangkan menjadikannya peran.</span>
    </summary>
    <ul>
      <li v-for="s in kueri.data.value" :key="`${s.efek}:${s.sasaran_kode}`">
        <span class="pil" :class="s.efek === 'tambah' ? 'pil-info' : 'pil-netral'">{{ s.efek === 'tambah' ? 'Tambah' : 'Cabut' }}</span>
        {{ s.sasaran_label }} <span class="ket">· {{ s.jumlah_akun }} akun</span>
      </li>
    </ul>
    <p class="ket">Buat peran berisi akses itu di Peran & izin, tugaskan ke akun-akun tersebut, lalu akhiri pengecualiannya.</p>
  </details>
</template>

<style scoped>
.saran {
  margin-bottom: var(--space-4);
  padding: var(--space-3) var(--space-4);
}
summary {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 32px;
  cursor: pointer;
  color: var(--text);
}
summary:focus-visible {
  outline: none;
  box-shadow: var(--ring);
  border-radius: 4px;
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  summary {
    min-height: 44px;
  }
}
summary svg {
  flex: none;
  color: var(--info);
}
ul {
  display: grid;
  gap: 6px;
  margin: var(--space-3) 0 var(--space-2);
  padding: 0;
  list-style: none;
}
.ket {
  margin: 0;
  color: var(--muted);
  font-size: 12.5px;
}
</style>
