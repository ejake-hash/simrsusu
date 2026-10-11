<script setup lang="ts">
import { computed, ref } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggal, formatTanggalWaktu } from '@/shared/utils/waktu'
import { batalkanPenugasan } from '../../apiPengaturan'
import { teksMenunggu } from '../../persetujuan'
import { bolehUbahAkun } from '../../pengaturan'
import type { AkunRinci, Penugasan } from '../../tipePengaturan'
import DaftarMenunggu from './DaftarMenunggu.vue'
import DialogAkhiri from './DialogAkhiri.vue'
import DialogPenugasan from './DialogPenugasan.vue'

/**
 * Penugasan peran akun: yang menunggu persetujuan (1.2d) paling atas, lalu yang berlaku, riwayat (batal/kedaluwarsa/belum
 * mulai) dilipat di bawah. Mengakhiri penugasan superadmin bisa 202 (menunggu superadmin lain).
 */
const props = defineProps<{ akun: AkunRinci }>()
const emit = defineEmits<{ berubah: [pesan: string] }>()

const LABEL_CAKUPAN: Record<Penugasan['cakupan_jenis'], string> = {
  rs: 'Seluruh RS',
  unit: 'Unit kerja',
  ruang: 'Ruang',
  poli: 'Poliklinik',
  ksm: 'KSM',
  depo: 'Depo farmasi',
  pasien_dirawat: 'Pasien yang dirawat',
}
/** Cakupan unit: "Unit kerja · KODE · Nama" (cakupan_label dari server, kontrak v0.3.0). */
const teksCakupan = (p: Penugasan) => [LABEL_CAKUPAN[p.cakupan_jenis], p.cakupan_label].filter(Boolean).join(' · ')

const berlaku = computed(() => props.akun.penugasan.filter((p) => p.berlaku_sekarang))
const riwayat = computed(() => props.akun.penugasan.filter((p) => !p.berlaku_sekarang))
const bolehTambah = computed(() => bolehUbahAkun(props.akun) && boleh('pengaturan.akun.input'))
const bolehAkhiri = computed(() => bolehUbahAkun(props.akun) && boleh('pengaturan.akun.perbaiki'))

function keteranganRiwayat(p: Penugasan): string {
  if (p.status === 'batal') return `Diakhiri ${formatTanggalWaktu(p.dibatalkan_pada) ?? ''}${p.dibatalkan_oleh ? ` oleh ${p.dibatalkan_oleh.nama}` : ''}: ${p.alasan_batal ?? ''}`
  if (p.berlaku_sampai && new Date(p.berlaku_sampai) < new Date()) return `Kedaluwarsa ${formatTanggal(p.berlaku_sampai)}`
  return `Mulai berlaku ${formatTanggal(p.berlaku_dari)}`
}

const dialogTambah = ref(false)
const diakhiri = ref<Penugasan | null>(null)
const dialogAkhiri = computed({
  get: () => diakhiri.value !== null,
  set: (v) => {
    if (!v) diakhiri.value = null
  },
})
async function akhiri(alasan: string) {
  const p = diakhiri.value!
  const hasil = await batalkanPenugasan(props.akun.id, p.id, alasan)
  emit('berubah', hasil.menunggu ? `Pengakhiran ${p.peran.nama} ${teksMenunggu(hasil.persetujuan)}.` : `Penugasan ${p.peran.nama} diakhiri.`)
}
</script>

<template>
  <div>
    <div class="kepala-tab">
      <h3>Penugasan peran</h3>
      <button v-if="bolehTambah" type="button" class="tombol" @click="dialogTambah = true"><Ikon nama="tambah" :ukuran="16" />Tugaskan peran</button>
    </div>
    <DaftarMenunggu :daftar="akun.menunggu_persetujuan ?? []" :jenis="['penugasan', 'pengakhiran_penugasan']" @berubah="emit('berubah', $event)" />
    <ul v-if="berlaku.length" class="daftar-baris">
      <li v-for="p in berlaku" :key="p.id" class="baris">
        <div>
          <b>{{ p.peran.nama }}</b>
          <p>Cakupan: {{ teksCakupan(p) }}</p>
          <p>Berlaku {{ formatTanggal(p.berlaku_dari) }} s.d. {{ formatTanggal(p.berlaku_sampai) ?? 'tidak dibatasi' }} · dasar: {{ p.dasar_pemberian }}</p>
          <p v-if="p.diberikan_oleh" class="pelaku">Diberikan oleh {{ p.diberikan_oleh.nama }}</p>
        </div>
        <button v-if="bolehAkhiri" type="button" class="tombol" @click="diakhiri = p">Akhiri</button>
      </li>
    </ul>
    <p v-else class="kosong">Belum ada peran yang berlaku. Akun ini hanya melihat Beranda.</p>

    <details v-if="riwayat.length" class="riwayat">
      <summary>Riwayat penugasan ({{ riwayat.length }})</summary>
      <ul class="daftar-baris">
        <li v-for="p in riwayat" :key="p.id" class="baris riwayat-baris">
          <div>
            <b>{{ p.peran.nama }}</b>
            <span class="pil" :class="p.status === 'batal' ? 'pil-netral' : 'pil-peringatan'">{{ p.status === 'batal' ? 'Diakhiri' : 'Tidak berlaku' }}</span>
            <p>{{ keteranganRiwayat(p) }}</p>
            <p>Dasar: {{ p.dasar_pemberian }}</p>
          </div>
        </li>
      </ul>
    </details>

    <DialogPenugasan v-model:open="dialogTambah" :akun-id="akun.id" :nama-akun="akun.nama" @selesai="emit('berubah', $event)" />
    <DialogAkhiri
      v-model:open="dialogAkhiri"
      :judul="`Akhiri penugasan ${diakhiri?.peran.nama ?? ''}?`"
      :akibat="`${akun.nama} kehilangan menu dan izin dari peran ${diakhiri?.peran.nama ?? ''} pada permintaan berikutnya, kecuali diberikan peran atau pengecualian lain.`"
      :aksi="akhiri"
    />
  </div>
</template>

<style scoped src="./tab-akun.css"></style>
