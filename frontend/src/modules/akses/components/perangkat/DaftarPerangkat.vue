<script setup lang="ts">
import Tombol from '@/design-system/components/Tombol.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { labelStatus } from '../../perangkat'
import type { PerangkatTepercaya } from '../../tipePerangkat'

/**
 * Daftar perangkat tepercaya (tiket 1.13): menu akun (milik sendiri) dan rincian akun (admin). Status berupa teks + ikon.
 * Tombol cabut hanya untuk perangkat aktif bila `bolehCabut`.
 */
defineProps<{ daftar: readonly PerangkatTepercaya[]; adaLagi: boolean; memuatLagi: boolean; bolehCabut: boolean; kosong: string }>()
const emit = defineEmits<{ muatLagi: []; cabut: [perangkat: PerangkatTepercaya] }>()
</script>

<template>
  <p v-if="!daftar.length" class="kosong">{{ kosong }}</p>
  <ul v-else class="daftar-perangkat">
    <li v-for="p in daftar" :key="p.id" class="perangkat" :class="{ nonaktif: p.status !== 'aktif' }">
      <div class="kepala">
        <Ikon nama="perangkat" :ukuran="18" />
        <span class="nama">{{ p.nama_perangkat }}</span>
        <span v-if="p.perangkat_ini" class="pil pil-merek">Perangkat ini</span>
        <span class="pil" :class="p.status === 'aktif' ? 'pil-ok' : 'pil-netral'">
          <Ikon :nama="p.status === 'aktif' ? 'centang' : 'gembok'" :ukuran="12" />{{ labelStatus(p) }}
        </span>
      </div>
      <dl class="rinci">
        <div>
          <dt>Dipercaya</dt>
          <dd>{{ formatTanggalWaktu(p.dibuat_pada) }}</dd>
        </div>
        <div>
          <dt>Terakhir dipakai</dt>
          <dd>{{ formatTanggalWaktu(p.terakhir_dipakai_pada) ?? 'belum pernah' }}</dd>
        </div>
        <div v-if="p.status === 'aktif'">
          <dt>Berlaku sampai</dt>
          <dd>{{ formatTanggalWaktu(p.berlaku_sampai) }}</dd>
        </div>
        <div v-else-if="p.status === 'kedaluwarsa'">
          <dt>Berakhir</dt>
          <dd>{{ formatTanggalWaktu(p.berlaku_sampai) }}</dd>
        </div>
        <div v-else-if="p.dicabut_pada">
          <dt>Dicabut</dt>
          <dd>
            {{ formatTanggalWaktu(p.dicabut_pada) }}<template v-if="p.dicabut_oleh"> oleh {{ p.dicabut_oleh.nama }}</template>
            <template v-if="p.keterangan_cabut">. Alasan: {{ p.keterangan_cabut }}</template>
          </dd>
        </div>
      </dl>
      <div v-if="bolehCabut && p.status === 'aktif'" class="aksi">
        <button type="button" class="tombol" :aria-label="`Cabut ${p.nama_perangkat}`" @click="emit('cabut', p)">Cabut</button>
      </div>
    </li>
  </ul>
  <div v-if="adaLagi" class="lagi">
    <Tombol :sibuk="memuatLagi" @click="emit('muatLagi')">{{ memuatLagi ? 'Memuat…' : 'Muat lagi' }}</Tombol>
  </div>
</template>

<style scoped>
.kosong {
  margin: 0;
  color: var(--muted);
}
.daftar-perangkat {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}
.perangkat {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: start;
  gap: var(--space-2) var(--space-3);
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
  background: var(--surface);
}
.perangkat.nonaktif {
  background: var(--bg);
}
.kepala {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
  grid-column: 1;
}
.nama {
  font-weight: var(--font-weight-semibold);
  overflow-wrap: anywhere;
}
.rinci {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1) var(--space-4);
  grid-column: 1;
  margin: 0;
  font-size: 12.5px;
}
.rinci dt {
  color: var(--muted);
}
.rinci dd {
  margin: 0;
}
.aksi {
  grid-column: 2;
  grid-row: 1 / span 2;
  align-self: center;
}
.lagi {
  margin-top: var(--space-3);
  text-align: center;
}
@media (max-width: 480px) {
  .perangkat {
    grid-template-columns: minmax(0, 1fr);
  }
  .aksi {
    grid-column: 1;
    grid-row: auto;
  }
}
</style>
