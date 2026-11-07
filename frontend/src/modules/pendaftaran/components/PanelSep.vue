<script setup lang="ts">
import { useMutation } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { ApiError } from '@/shared/api/klien'
import { bacaPeserta, bacaRujukan, terbitkanSep } from '../apiSep'
import { LABEL_CARA_BAYAR } from '../kunjungan'
import { formatNoRm, formatTanggal } from '../pasien'
import type { Kunjungan } from '../tipeKunjungan'
import type { AsalRujukan, RujukanBpjs, Sep } from '../tipeSep'

/**
 * Terbitkan SEP untuk kunjungan JKN (tiket 2.7, kontrak v0.21.0): periksa kepesertaan, periksa rujukan (sisa ≤ 7 hari
 * ditandai, kedaluwarsa menolak), lalu terbitkan. BPJS menolak → kode dan pesan BPJS tampil apa adanya; kunjungan tetap
 * tersimpan. BPJS tidak terjangkau (503) → "Simpan, terbitkan SEP nanti" (tugas masuk kotak kerja). Pasien ditandai nama,
 * No. RM, dan tanggal lahir (PRD 5.1 butir 9).
 */
const props = defineProps<{ kunjungan: Kunjungan; noKartu?: string | null }>()
defineEmits<{ tutup: [] }>()

const nomorKartu = ref(props.noKartu ?? '')
const noRujukan = ref('')
const asal = ref<'' | AsalRujukan>('')
const konfirmasi = ref(false)
const hasil = ref<Sep | null>(null)
/** Satu kunci per niat terbit; baru setelah berhasil atau input berubah. Mode tunda punya kunci sendiri. */
let kunciTerbit = crypto.randomUUID()

const peserta = useMutation({ mutationFn: () => bacaPeserta(nomorKartu.value.trim(), props.kunjungan.tanggal) })
const rujukan = useMutation({ mutationFn: () => bacaRujukan(noRujukan.value.trim(), props.kunjungan.tanggal, asal.value || null) })
const terbit = useMutation({
  mutationFn: (tunda: boolean) => terbitkanSep(props.kunjungan.id, { no_rujukan: noRujukan.value.trim(), asal_rujukan: asal.value || null, tunda }, `${kunciTerbit}-${tunda}`),
  onSuccess: (sep) => {
    hasil.value = sep
    kunciTerbit = crypto.randomUUID()
    konfirmasi.value = false
    tampilkanToast(sep.status === 'terbit' ? `SEP terbit untuk ${props.kunjungan.pasien.nama}.` : 'SEP tersimpan, menunggu dikirim ke BPJS.', 'ok')
  },
  onError: () => {
    konfirmasi.value = false
  },
})

watch([noRujukan, asal], () => {
  kunciTerbit = crypto.randomUUID()
  rujukan.reset()
  terbit.reset()
})
watch(nomorKartu, () => peserta.reset())

const dataRujukan = computed<RujukanBpjs | null>(() => rujukan.data.value ?? null)
const kedaluwarsa = computed(() => dataRujukan.value?.status_masa_berlaku === 'kedaluwarsa')
const bolehTerbit = computed(() => noRujukan.value.trim().length > 0 && !kedaluwarsa.value)
const beda = computed(() => {
  const l = peserta.data.value?.tanggal_lahir
  return Boolean(l && props.kunjungan.pasien.tanggal_lahir && l !== props.kunjungan.pasien.tanggal_lahir)
})

/** Galat per langkah; `bpjs_menolak` membawa kode dan pesan BPJS apa adanya di `rincian`. */
const galat = computed(() => {
  const baris: { langkah: string; e: ApiError; bpjs: { kode: string; pesan: string }[] }[] = []
  for (const [langkah, e] of [['Kepesertaan', peserta.error.value], ['Rujukan', rujukan.error.value], ['SEP', terbit.error.value]] as [string, unknown][]) {
    if (!(e instanceof ApiError)) continue
    const bpjs = e.kode === 'bpjs_menolak' ? e.rincian.map((r) => ({ kode: String(r.kode_bpjs ?? ''), pesan: String(r.pesan_bpjs ?? '') })) : []
    baris.push({ langkah, e, bpjs })
  }
  return baris
})
const bpjsTakTerjangkau = computed(() => galat.value.some((g) => g.e.status === 503 && g.e.kode === 'integrasi_tidak_tersedia'))
const peringatanHasil = computed(() => hasil.value?.peringatan ?? [])
</script>

<template>
  <section class="kartu" aria-labelledby="judul-sep">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-sep">Terbitkan SEP</h2>
        <p>
          <b>{{ kunjungan.pasien.nama }}</b> · No. RM {{ formatNoRm(kunjungan.pasien.no_rm) }} · lahir {{ formatTanggal(kunjungan.pasien.tanggal_lahir) }}
          · kunjungan <code>{{ kunjungan.nomor }}</code> · {{ formatTanggal(kunjungan.tanggal) }} · {{ LABEL_CARA_BAYAR[kunjungan.cara_bayar] }}
        </p>
      </div>
      <Tombol varian="tautan" @click="$emit('tutup')">Tutup</Tombol>
    </div>

    <div v-if="hasil" class="isi" role="status">
      <Pesan v-if="hasil.status === 'terbit'" jenis="ok">SEP terbit. No. SEP <code>{{ hasil.no_sep }}</code>.</Pesan>
      <Pesan v-else jenis="info">
        SEP tersimpan dan menunggu dikirim ke BPJS. Tugas "SEP tertunda" masuk kotak kerja petugas pendaftaran; kunjungan tetap tersimpan dan pasien tetap dapat dilayani.
      </Pesan>
      <Pesan v-for="p in peringatanHasil" :key="p.kode" jenis="peringatan">{{ p.pesan }}</Pesan>
    </div>

    <div v-else class="isi">
      <div class="langkah">
        <h3>1. Kepesertaan</h3>
        <div class="baris">
          <KolomIsian v-slot="a" label="No. kartu BPJS atau NIK" petunjuk="13 digit (No. kartu) atau 16 digit (NIK).">
            <input v-bind="a" v-model="nomorKartu" class="isian kode" inputmode="numeric" autocomplete="off" maxlength="20" />
          </KolomIsian>
          <Tombol :disabled="!nomorKartu.trim() || undefined" :sibuk="peserta.isPending.value" @click="peserta.mutate()">Periksa kepesertaan</Tombol>
        </div>
        <template v-if="peserta.data.value">
          <p>
            <Lencana :jenis="peserta.data.value.status.aktif ? 'ok' : 'bahaya'">{{ peserta.data.value.status.aktif ? 'Peserta aktif' : 'Peserta tidak aktif' }}</Lencana>
            {{ peserta.data.value.status.keterangan }}
          </p>
          <p class="ket">
            {{ peserta.data.value.nama }} · lahir {{ formatTanggal(peserta.data.value.tanggal_lahir) }} · {{ peserta.data.value.jenis_peserta?.nama ?? '—' }} · hak kelas
            {{ peserta.data.value.kelas_hak?.nama ?? '—' }} · FKTP {{ peserta.data.value.faskes_tingkat_pertama?.nama ?? '—' }}
          </p>
          <Pesan v-if="!peserta.data.value.status.aktif" jenis="peringatan">
            Peserta tidak aktif pada tanggal kunjungan. Tanyakan pasien: bila setuju, ganti cara bayar ke umum / penjamin lain.
          </Pesan>
          <Pesan v-if="beda" jenis="peringatan">Tanggal lahir di BPJS berbeda dengan data pasien. Pastikan nomor ini milik pasien di atas.</Pesan>
        </template>
      </div>

      <div class="langkah">
        <h3>2. Rujukan</h3>
        <div class="baris">
          <KolomIsian v-slot="a" label="Nomor rujukan" wajib>
            <input v-bind="a" v-model="noRujukan" class="isian kode" autocomplete="off" maxlength="40" />
          </KolomIsian>
          <KolomIsian v-slot="a" label="Asal rujukan">
            <select v-bind="a" v-model="asal" class="isian">
              <option value="">Cari di keduanya</option>
              <option value="fktp">FKTP</option>
              <option value="rs">Antar-RS</option>
            </select>
          </KolomIsian>
          <Tombol :disabled="!noRujukan.trim() || undefined" :sibuk="rujukan.isPending.value" @click="rujukan.mutate()">Periksa rujukan</Tombol>
        </div>
        <template v-if="dataRujukan">
          <p>
            <Lencana :jenis="kedaluwarsa ? 'bahaya' : dataRujukan.status_masa_berlaku === 'hampir_habis' ? 'peringatan' : 'ok'">
              {{ kedaluwarsa ? 'Rujukan kedaluwarsa' : dataRujukan.status_masa_berlaku === 'hampir_habis' ? 'Rujukan hampir habis' : 'Rujukan berlaku' }}
            </Lencana>
            berlaku sampai {{ formatTanggal(dataRujukan.berlaku_sampai) }}
          </p>
          <p class="ket">
            Atas nama {{ dataRujukan.peserta.nama }} · dari {{ dataRujukan.faskes_asal.nama }} ({{ dataRujukan.asal === 'fktp' ? 'FKTP' : 'antar-RS' }}) ·
            poli {{ dataRujukan.poli_tujuan.nama }} · diagnosis {{ dataRujukan.diagnosis.kode }} {{ dataRujukan.diagnosis.nama }}
          </p>
          <Pesan v-if="kedaluwarsa" jenis="error">
            Rujukan kedaluwarsa sejak {{ formatTanggal(dataRujukan.berlaku_sampai) }}; SEP tidak dapat diterbitkan untuk kunjungan ini. Minta rujukan baru dari pasien.
          </Pesan>
          <Pesan v-else-if="dataRujukan.status_masa_berlaku === 'hampir_habis'" jenis="peringatan">
            Sisa masa berlaku rujukan {{ dataRujukan.sisa_hari }} hari. SEP tetap dapat terbit; ingatkan pasien meminta rujukan baru.
          </Pesan>
        </template>
      </div>

      <div v-for="g in galat" :key="g.langkah" role="alert">
        <Pesan jenis="error">
          {{ g.langkah }}: {{ g.e.message }}
          <ul v-if="g.bpjs.length" class="rincian">
            <li v-for="(r, i) in g.bpjs" :key="i">Kode BPJS {{ r.kode }}: {{ r.pesan }}</li>
          </ul>
        </Pesan>
      </div>
      <Pesan v-if="galat.some((g) => g.e.kode === 'bpjs_menolak') && !bpjsTakTerjangkau" jenis="info">Kunjungan tetap tersimpan. SEP dapat diajukan ulang setelah data diperbaiki.</Pesan>
      <Pesan v-if="bpjsTakTerjangkau" jenis="peringatan">
        Layanan BPJS belum dapat dihubungi. Kunjungan tetap tersimpan dan pasien tetap dapat dilayani; SEP dikirim otomatis begitu layanan pulih.
      </Pesan>

      <div class="aksi">
        <Tombol v-if="bpjsTakTerjangkau" :disabled="!bolehTerbit || undefined" :sibuk="terbit.isPending.value" @click="terbit.mutate(true)">Simpan, terbitkan SEP nanti</Tombol>
        <Tombol v-can="'pendaftaran.sep.input'" varian="utama" :disabled="!bolehTerbit || undefined" :sibuk="terbit.isPending.value" @click="konfirmasi = true">Terbitkan SEP</Tombol>
      </div>
    </div>

    <Dialog v-model:open="konfirmasi" judul="Terbitkan SEP" :uraian="`${kunjungan.pasien.nama} · No. RM ${formatNoRm(kunjungan.pasien.no_rm)} · lahir ${formatTanggal(kunjungan.pasien.tanggal_lahir)}`" :sibuk="terbit.isPending.value">
      <p>SEP yang sudah terbit tidak dapat dihapus dari layar ini. Pastikan rujukan dan kepesertaan sudah sesuai.</p>
      <template #aksi>
        <Tombol @click="konfirmasi = false">Kembali</Tombol>
        <Tombol varian="utama" :sibuk="terbit.isPending.value" @click="terbit.mutate(false)">Terbitkan SEP</Tombol>
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.isi {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
}
.langkah h3 {
  margin: 0 0 var(--space-2);
  font-size: 14px;
  color: var(--muted);
}
.langkah p {
  margin: var(--space-2) 0 0;
}
.baris {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: var(--space-3);
}
.ket {
  font-size: 13px;
  color: var(--muted);
}
.rincian {
  margin: var(--space-1) 0 0;
  padding-left: var(--space-4);
}
.aksi {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-3);
  padding-top: var(--space-3);
  border-top: 1px solid var(--border);
}
</style>
