<script setup lang="ts">
import { computed, ref } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggal, formatTanggalWaktu } from '@/shared/utils/waktu'
import { batalkanIzinKhusus } from '../../apiPengaturan'
import type { IndeksMatriks } from '../../matriks'
import { bolehUbahAkun } from '../../pengaturan'
import type { AkunRinci, IzinKhusus } from '../../tipePengaturan'
import DialogAkhiri from './DialogAkhiri.vue'
import DaftarMenunggu from './DaftarMenunggu.vue'
import DialogIzinKhusus from './DialogIzinKhusus.vue'

/**
 * Pengecualian per akun (FR-09-13, MD-1): tambah/cabut di luar peran. Mengakhiri = dialog dengan alasan dan
 * kalimat akibat, bukan tombol "×"; baris yang diakhiri tetap tampil sebagai riwayat.
 */
const props = defineProps<{ akun: AkunRinci; indeks: IndeksMatriks | null }>()
const emit = defineEmits<{ berubah: [pesan: string] }>()

const berlaku = computed(() => props.akun.izin_khusus.filter((k) => k.berlaku_sekarang))
const riwayat = computed(() => props.akun.izin_khusus.filter((k) => !k.berlaku_sekarang))
const bolehTambah = computed(() => bolehUbahAkun(props.akun) && boleh('pengaturan.akun.input'))
const bolehAkhiri = computed(() => bolehUbahAkun(props.akun) && boleh('pengaturan.akun.perbaiki'))

/** Kalimat akibat mengakhiri (PRD 9.3): cabut berakhir → akses kembali sesuai peran; tambah berakhir → akses hilang. */
function akibat(k: IzinKhusus | null): string {
  if (!k) return ''
  return k.efek === 'cabut'
    ? `Akses ${k.sasaran_label} untuk ${props.akun.nama} kembali sesuai perannya.`
    : `${props.akun.nama} kehilangan akses ${k.sasaran_label} yang diberikan lewat pengecualian ini, kecuali perannya memberi akses yang sama.`
}

function keteranganRiwayat(k: IzinKhusus): string {
  if (k.status === 'batal') return `Diakhiri ${formatTanggalWaktu(k.dibatalkan_pada) ?? ''}${k.dibatalkan_oleh ? ` oleh ${k.dibatalkan_oleh.nama}` : ''}: ${k.alasan_batal ?? ''}`
  if (k.berlaku_sampai && new Date(k.berlaku_sampai) < new Date()) return `Kedaluwarsa ${formatTanggal(k.berlaku_sampai)}`
  return `Mulai berlaku ${formatTanggal(k.berlaku_dari)}`
}

const dialogTambah = ref(false)
const diakhiri = ref<IzinKhusus | null>(null)
const dialogAkhiri = computed({
  get: () => diakhiri.value !== null,
  set: (v) => {
    if (!v) diakhiri.value = null
  },
})
async function akhiri(alasan: string) {
  const k = diakhiri.value!
  await batalkanIzinKhusus(props.akun.id, k.id, alasan)
  emit('berubah', `Pengecualian ${k.sasaran_label} diakhiri.`)
}
</script>

<template>
  <div>
    <div class="kepala-tab">
      <h3>Pengecualian per akun</h3>
      <button v-if="bolehTambah && indeks" type="button" class="tombol" @click="dialogTambah = true"><Ikon nama="tambah" :ukuran="16" />Tambah pengecualian</button>
    </div>
    <DaftarMenunggu :daftar="akun.menunggu_persetujuan ?? []" :jenis="['izin_khusus']" @berubah="emit('berubah', $event)" />
    <ul v-if="berlaku.length" class="daftar-baris">
      <li v-for="k in berlaku" :key="k.id" class="baris">
        <div>
          <span class="pil" :class="k.efek === 'cabut' ? 'pil-bahaya' : 'pil-ok'">{{ k.efek === 'cabut' ? 'Dicabut' : 'Ditambah' }}</span>
          <b>{{ k.sasaran_label }}</b>
          <span v-if="k.sensitif" class="pil pil-peringatan"><Ikon nama="gembok" :ukuran="12" />sensitif</span>
          <p>{{ k.alasan }}</p>
          <p>{{ k.berlaku_sampai ? `Berlaku s.d. ${formatTanggal(k.berlaku_sampai)}` : 'Berlaku sampai diakhiri' }} · dasar: {{ k.dasar_pemberian }}</p>
          <p v-if="k.diberikan_oleh" class="pelaku">Oleh {{ k.diberikan_oleh.nama }}, {{ formatTanggalWaktu(k.dibuat_pada) }}</p>
        </div>
        <button v-if="bolehAkhiri" type="button" class="tombol" @click="diakhiri = k">Akhiri</button>
      </li>
    </ul>
    <p v-else class="kosong">Tidak ada pengecualian yang berlaku. Akses akun ini sepenuhnya dari perannya.</p>

    <details v-if="riwayat.length" class="riwayat">
      <summary>Riwayat pengecualian ({{ riwayat.length }})</summary>
      <ul class="daftar-baris">
        <li v-for="k in riwayat" :key="k.id" class="baris riwayat-baris">
          <div>
            <span class="pil pil-netral">{{ k.efek === 'cabut' ? 'Cabut' : 'Tambah' }} · {{ k.status === 'batal' ? 'diakhiri' : 'tidak berlaku' }}</span>
            <b>{{ k.sasaran_label }}</b>
            <p>{{ keteranganRiwayat(k) }}</p>
            <p>Alasan pemberian: {{ k.alasan }} · dasar: {{ k.dasar_pemberian }}</p>
          </div>
        </li>
      </ul>
    </details>

    <DialogIzinKhusus v-if="indeks" v-model:open="dialogTambah" :akun-id="akun.id" :nama-akun="akun.nama" :indeks="indeks" @selesai="emit('berubah', $event)" />
    <DialogAkhiri v-model:open="dialogAkhiri" :judul="`Akhiri pengecualian ${diakhiri?.sasaran_label ?? ''}?`" :akibat="akibat(diakhiri)" :aksi="akhiri" />
  </div>
</template>

<style scoped src="./tab-akun.css"></style>
