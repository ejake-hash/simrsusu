<script setup lang="ts">
import Ikon from '@/design-system/ikon/Ikon.vue'
import { formatTanggalWaktu } from '@/shared/utils/waktu'

/**
 * Hasil verifikasi tanda tangan sebuah objek (tiket 1.6 fe 3): "Ditandatangani <nama>, <waktu> · terverifikasi" per
 * penanda tangan, atau peringatan bila isi berubah sesudah ditandatangani / tanda tangan tidak sah. Kredensial yang
 * sudah dicabut karena reset tetap sah untuk tanda tangan lama (ditandai keterangan). Tombol verifikasi ulang
 * memanggil server lagi (pemanggil yang memuat).
 */
export interface TandaTanganTampil {
  id: string
  penanda_tangan: { nama: string; nip: string | null }
  ditandatangani_pada: string
  sidik_kunci: string
  hash_isi: string
  kredensial_dicabut: boolean
  verifikasi: { status: 'sah' | 'isi_berubah' | 'tanda_tangan_tidak_sah'; diperiksa_pada: string }
}
defineProps<{ tandaTangan: TandaTanganTampil[]; memuat?: boolean; error?: string | null }>()
const emit = defineEmits<{ verifikasi: [] }>()
</script>

<template>
  <div class="badge-ttd" :aria-busy="memuat || undefined">
    <p v-if="error" class="catatan catatan-bahaya" role="alert">
      <Ikon nama="peringatan" :ukuran="16" />
      <span>{{ error }}</span>
    </p>
    <p v-else-if="!tandaTangan.length && !memuat" class="ket">Belum ditandatangani.</p>
    <ul v-else class="daftar">
      <li v-for="t in tandaTangan" :key="t.id">
        <span v-if="t.verifikasi.status === 'sah'" class="pil pil-ok" :title="`Sidik kunci ${t.sidik_kunci} · hash ${t.hash_isi.slice(0, 16)}…`">
          <Ikon nama="centang" :ukuran="12" />Ditandatangani {{ t.penanda_tangan.nama }}, {{ formatTanggalWaktu(t.ditandatangani_pada) }} · terverifikasi
        </span>
        <span v-else-if="t.verifikasi.status === 'isi_berubah'" class="pil pil-bahaya">
          <Ikon nama="peringatan" :ukuran="12" />Isi berubah sesudah ditandatangani {{ t.penanda_tangan.nama }}, {{ formatTanggalWaktu(t.ditandatangani_pada) }}
        </span>
        <span v-else class="pil pil-bahaya"><Ikon nama="peringatan" :ukuran="12" />Tanda tangan {{ t.penanda_tangan.nama }} tidak sah</span>
        <span v-if="t.kredensial_dicabut" class="ket">Kunci penanda tangan sudah diganti karena reset; tanda tangan lama tetap diperiksa dengan kunci saat itu.</span>
      </li>
    </ul>
    <button type="button" class="tombol tombol-tautan" :disabled="memuat" @click="emit('verifikasi')">{{ memuat ? 'Memeriksa…' : 'Verifikasi ulang' }}</button>
  </div>
</template>

<style scoped>
.badge-ttd {
  display: grid;
  justify-items: start;
  gap: var(--space-1);
}
.daftar {
  display: grid;
  gap: var(--space-1);
  margin: 0;
  padding: 0;
  list-style: none;
}
.daftar li {
  display: grid;
  gap: 2px;
}
.pil {
  white-space: normal;
}
.ket {
  margin: 0;
  color: var(--muted);
  font-size: 12px;
}
.catatan {
  margin: 0;
}
</style>
