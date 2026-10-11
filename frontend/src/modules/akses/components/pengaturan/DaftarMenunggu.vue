<script setup lang="ts">
import { computed, ref } from 'vue'
import { useSesiStore } from '@/app/sesi'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { batalkanPersetujuan } from '../../apiPersetujuan'
import type { JenisObjekPersetujuan, PersetujuanAkses } from '../../tipePersetujuan'
import DialogAkhiri from './DialogAkhiri.vue'

/**
 * Pengajuan untuk akun ini yang masih menunggu persetujuan (AkunRinci.menunggu_persetujuan, tiket 1.2d). Belum berlaku,
 * jadi tampil terpisah dari penugasan/pengecualian. Pengaju bisa menariknya; rincian lengkap ada di Persetujuan akses
 * (hanya untuk pengaju, penerima, atau penyetuju — admin lain melihat ringkasan ini saja).
 */
const props = defineProps<{ daftar: PersetujuanAkses[]; jenis: JenisObjekPersetujuan[] }>()
const emit = defineEmits<{ berubah: [pesan: string] }>()
const sesi = useSesiStore()

const tampil = computed(() => props.daftar.filter((p) => props.jenis.includes(p.objek.jenis)))
const milikSaya = (p: PersetujuanAkses) => p.diajukan_oleh?.id === sesi.pengguna?.id

const ditarik = ref<PersetujuanAkses | null>(null)
const dialog = computed({
  get: () => ditarik.value !== null,
  set: (v) => {
    if (!v) ditarik.value = null
  },
})
async function tarik(alasan: string) {
  const p = ditarik.value!
  await batalkanPersetujuan(p.id, alasan)
  emit('berubah', `Pengajuan ${p.nomor} ditarik.`)
}
</script>

<template>
  <section v-if="tampil.length" class="menunggu" aria-labelledby="judul-menunggu">
    <h3 id="judul-menunggu">Menunggu persetujuan ({{ tampil.length }})</h3>
    <ul class="daftar-baris">
      <li v-for="p in tampil" :key="p.id" class="baris baris-menunggu">
        <div>
          <b>{{ p.ringkasan }}</b>
          <span class="pil pil-peringatan"><Ikon nama="jam" :ukuran="12" />Belum berlaku</span>
          <p>
            Penyetuju: {{ p.grup.jumlah > 1 ? `${p.grup.disetujui} dari ${p.grup.jumlah} sudah menyetujui` : (p.penyetuju_peran?.nama ?? 'pemilik data') }}
            · kedaluwarsa {{ formatTanggalWaktu(p.kedaluwarsa_pada) }}
          </p>
          <p class="pelaku">{{ p.nomor }} · diajukan {{ p.diajukan_oleh?.nama ?? 'sistem' }}, {{ formatTanggalWaktu(p.diajukan_pada) }} · dasar: {{ p.dasar }}</p>
        </div>
        <div class="aksi">
          <RouterLink v-if="milikSaya(p)" class="tombol" :to="{ path: '/pengaturan/persetujuan', query: { tab: 'saya_ajukan', id: p.id } }">Rincian</RouterLink>
          <button v-if="milikSaya(p)" type="button" class="tombol" @click="ditarik = p">Tarik pengajuan</button>
        </div>
      </li>
    </ul>
    <DialogAkhiri
      v-model:open="dialog"
      :judul="`Tarik pengajuan ${ditarik?.nomor ?? ''}?`"
      :akibat="`Pengajuan “${ditarik?.ringkasan ?? ''}” dibatalkan untuk semua penyetujunya dan tidak akan berlaku.`"
      :aksi="tarik"
      label-alasan="Alasan menarik"
      label-aksi="Tarik pengajuan"
    />
  </section>
</template>

<style scoped src="./tab-akun.css"></style>
<style scoped>
.menunggu {
  margin-bottom: var(--space-4);
}
.menunggu h3 {
  margin-bottom: var(--space-2);
}
.baris-menunggu {
  flex-direction: column;
  border-style: dashed;
  background: var(--bg);
}
/* Panel rincian akun sempit: tombol di bawah teks, bukan menekan teks menjadi kolom sempit. */
.aksi {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
</style>
