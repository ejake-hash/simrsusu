<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useSesiStore } from '@/app/sesi'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggal, formatTanggalWaktu } from '@/shared/utils/waktu'
import { ambilLabelAkun, batalkanLabelAkun } from '../../apiPengaturan'
import { bolehUbahAkun, KUNCI, pesanError } from '../../pengaturan'
import type { AkunRinci, IzinLabelAkun } from '../../tipePengaturan'
import DialogAkhiri from './DialogAkhiri.vue'
import DialogIzinLabel from './DialogIzinLabel.vue'

/**
 * Izin label sensitivitas akun (lapis 4, PRD 9.2.2; tiket 1.5 fe 2). Pemberian selalu lewat persetujuan pejabat PDP
 * dan berbatas waktu; pencabutan langsung berlaku. Izin label saja belum cukup: data berlabel juga butuh relasi
 * perawatan (fase 2), jadi sampai itu data berlabel tetap tampil "Terbatas".
 */
const props = defineProps<{ akun: AkunRinci }>()
const emit = defineEmits<{ berubah: [pesan: string] }>()

const qc = useQueryClient()
const sesi = useSesiStore()
// Izin label per akun sedikit: satu halaman berbatas 200 (kontrak: maksimum batas) memuat seluruh riwayat.
const kueri = useQuery({ queryKey: computed(() => KUNCI.labelAkun(props.akun.id)), queryFn: async () => (await ambilLabelAkun(props.akun.id, null, 200)).data })

const berlaku = computed(() => (kueri.data.value ?? []).filter((l) => l.berlaku_sekarang))
const menunggu = computed(() => (kueri.data.value ?? []).filter((l) => l.status === 'menunggu'))
const riwayat = computed(() => (kueri.data.value ?? []).filter((l) => !l.berlaku_sekarang && l.status !== 'menunggu'))
const bolehAjukan = computed(() => bolehUbahAkun(props.akun) && boleh('pengaturan.akun.input'))
const bolehCabut = computed(() => bolehUbahAkun(props.akun) && boleh('pengaturan.akun.perbaiki'))

const LABEL_STATUS: Record<IzinLabelAkun['status'], string> = {
  menunggu: 'menunggu persetujuan',
  berlaku: 'berlaku',
  berakhir: 'berakhir',
  batal: 'dicabut',
  ditolak: 'tidak disetujui',
}

function keteranganRiwayat(l: IzinLabelAkun): string {
  if (l.status === 'batal') return `Dicabut ${formatTanggalWaktu(l.dibatalkan_pada) ?? ''}${l.dibatalkan_oleh ? ` oleh ${l.dibatalkan_oleh.nama}` : ''}: ${l.alasan_batal ?? ''}`
  if (l.status === 'ditolak') return `Pengajuan ${l.persetujuan.nomor} ditolak, kedaluwarsa, atau ditarik.`
  return `Berakhir ${formatTanggal(l.berlaku_sampai)}`
}

function segarkan(pesan: string) {
  void qc.invalidateQueries({ queryKey: KUNCI.labelAkun(props.akun.id) })
  void qc.invalidateQueries({ queryKey: KUNCI.persetujuan })
  emit('berubah', pesan)
}

const dialogAjukan = ref(false)
const dicabut = ref<IzinLabelAkun | null>(null)
const dialogCabut = computed({
  get: () => dicabut.value !== null,
  set: (v) => {
    if (!v) dicabut.value = null
  },
})
async function cabut(alasan: string) {
  const l = dicabut.value!
  await batalkanLabelAkun(props.akun.id, l.id, alasan)
  segarkan(`Izin label ${l.label.nama} untuk ${props.akun.nama} dicabut.`)
}
</script>

<template>
  <div>
    <div class="kepala-tab">
      <h3>Izin label sensitivitas</h3>
      <button v-if="bolehAjukan" type="button" class="tombol" @click="dialogAjukan = true"><Ikon nama="tambah" :ukuran="16" />Ajukan izin label</button>
    </div>
    <p class="catatan catatan-netral catatan-atas">
      <Ikon nama="gembok" :ukuran="16" />
      <span>Data berlabel (mis. HIV, psikiatri) hanya terbuka bila akun punya izin label yang berlaku <b>dan</b> merawat pasiennya. Tanpa keduanya, data itu tampil sebagai "Terbatas".</span>
    </p>

    <p v-if="kueri.isPending.value" class="kosong" role="status">Memuat izin label…</p>
    <div v-else-if="kueri.isError.value" role="alert">
      <p class="catatan catatan-bahaya">{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <template v-else>
      <ul v-if="menunggu.length" class="daftar-baris" aria-label="Menunggu persetujuan">
        <li v-for="l in menunggu" :key="l.id" class="baris">
          <div>
            <span class="pil pil-peringatan">Menunggu</span>
            <b>{{ l.label.nama }}</b>
            <p>Pengajuan {{ l.persetujuan.nomor }} menunggu persetujuan pejabat PDP · berlaku s.d. {{ formatTanggal(l.berlaku_sampai) }}</p>
            <p>{{ l.alasan }} · dasar: {{ l.dasar_pemberian }}</p>
          </div>
          <RouterLink v-if="l.diberikan_oleh?.id === sesi.pengguna?.id" class="tombol" :to="{ path: '/pengaturan/persetujuan', query: { tab: 'saya_ajukan', id: l.persetujuan.id } }">Lihat pengajuan</RouterLink>
        </li>
      </ul>
      <ul v-if="berlaku.length" class="daftar-baris" aria-label="Izin label yang berlaku">
        <li v-for="l in berlaku" :key="l.id" class="baris">
          <div>
            <span class="pil pil-ok">Berlaku</span>
            <b>{{ l.label.nama }}</b>
            <p>Sejak {{ formatTanggal(l.berlaku_dari) }} s.d. {{ formatTanggal(l.berlaku_sampai) }} · {{ l.persetujuan.nomor }}</p>
            <p>{{ l.alasan }} · dasar: {{ l.dasar_pemberian }}</p>
            <p v-if="l.disetujui_oleh" class="pelaku">Disetujui {{ l.disetujui_oleh.nama }}<template v-if="l.diberikan_oleh">, diajukan {{ l.diberikan_oleh.nama }}</template></p>
          </div>
          <button v-if="bolehCabut" type="button" class="tombol" @click="dicabut = l">Cabut</button>
        </li>
      </ul>
      <p v-if="!berlaku.length && !menunggu.length" class="kosong">Akun ini tidak punya izin label yang berlaku.</p>

      <details v-if="riwayat.length" class="riwayat">
        <summary>Riwayat izin label ({{ riwayat.length }})</summary>
        <ul class="daftar-baris">
          <li v-for="l in riwayat" :key="l.id" class="baris riwayat-baris">
            <div>
              <span class="pil pil-netral">{{ LABEL_STATUS[l.status] }}</span>
              <b>{{ l.label.nama }}</b>
              <p>{{ keteranganRiwayat(l) }}</p>
              <p>Alasan pemberian: {{ l.alasan }} · dasar: {{ l.dasar_pemberian }}</p>
            </div>
          </li>
        </ul>
      </details>
    </template>

    <DialogIzinLabel v-model:open="dialogAjukan" :akun-id="akun.id" :nama-akun="akun.nama" @selesai="segarkan" />
    <DialogAkhiri
      v-model:open="dialogCabut"
      :judul="`Cabut izin label ${dicabut?.label.nama ?? ''}?`"
      :akibat="`${akun.nama} langsung kehilangan akses ke data berlabel ${dicabut?.label.nama ?? ''}.`"
      label-alasan="Alasan mencabut"
      label-aksi="Cabut"
      :aksi="cabut"
    />
  </div>
</template>

<style scoped src="./tab-akun.css"></style>
<style scoped>
.catatan-atas {
  margin: 0 0 var(--space-3);
}
</style>
