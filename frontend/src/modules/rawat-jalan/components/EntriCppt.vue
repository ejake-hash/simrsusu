<script setup lang="ts">
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed, reactive, ref } from 'vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import TtdModal from '@/design-system/components/TtdModal.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { useSesiStore } from '@/app/sesi'
import { perbaruiStatusTtd } from '@/modules/ttd/ttd'
import { ApiError } from '@/shared/api/klien'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { adendumCppt, sahkanCppt, ubahCppt } from '../api'
import { adaIsi, isiSoap, KUNCI_WORKLIST, LABEL_PROFESI, LABEL_TATANAN, pesanCppt, sisaPerbaikan, type IsianSoap } from '../rawatJalan'
import type { Cppt } from '../tipe'
import FormSoap from './FormSoap.vue'

/**
 * Satu entri CPPT di linimasa (FR-07-04, FR-26-06, FR-26-18). Aksi mengikuti `dapat` dari server: ubah draf; sahkan dengan
 * PIN T1 milik penulis sendiri (aturan UX 5.1); perbaiki entri sah ≤ 2 × 24 jam dengan alasan dan tanda tangan ulang;
 * sesudah batas, adendum bertanda tangan (B-14). Isi lama tersimpan server sebagai versi.
 */
/** `identitas` = nama + No. RM + tanggal lahir pasien, ditampilkan di modal tanda tangan (PRD 5.1 butir 9). */
const props = defineProps<{ entri: Cppt; sekarang: number; identitas: string }>()
const qc = useQueryClient()
const sesi = useSesiStore()
const pengguna = computed(() => ({ id: sesi.pengguna?.id ?? '', nama: sesi.pengguna?.pegawai?.nama ?? sesi.pengguna?.nama_pengguna ?? '' }))
const ttdAktif = computed(() => Boolean(sesi.pengguna?.ttd?.aktif))

const e = computed(() => props.entri)
const etag = computed(() => `"${e.value.versi}"`)
const sisa = computed(() => sisaPerbaikan(e.value.batas_perbaikan_pada, props.sekarang))
const BAGIAN = [['S', 'subjektif'], ['O', 'objektif'], ['A', 'asesmen'], ['P', 'plan']] as const

type Mode = 'baca' | 'ubah' | 'adendum'
const mode = ref<Mode>('baca')
const f = reactive<IsianSoap>({ subjektif: '', objektif: '', asesmen: '', plan: '', label: '' })
const alasan = ref('')
const isiAdendum = ref('')
/** Diisi saat perbaikan ditolak karena batas 2 × 24 jam lewat selagi formulir terbuka. */
const batasLewat = ref(false)
const galat = ref<string | null>(null)

function buka(m: Mode) {
  galat.value = null
  batasLewat.value = false
  alasan.value = ''
  isiAdendum.value = ''
  if (m === 'ubah') Object.assign(f, { ...Object.fromEntries(BAGIAN.map(([, k]) => [k, e.value.isi[k] ?? ''])), label: e.value.label ?? '' })
  mode.value = m
}
const segarkan = () => qc.invalidateQueries({ queryKey: KUNCI_WORKLIST.cppt })

const simpanDraf = useMutation({
  mutationFn: () => ubahCppt(e.value.id, etag.value, isiSoap(f)),
  onSuccess: () => {
    tampilkanToast('Draf CPPT disimpan.', 'ok')
    mode.value = 'baca'
    void segarkan()
  },
  onError: (x) => (galat.value = pesanCppt(x)),
})

// ── Tanda tangan: sahkan, perbaikan entri sah, adendum. Galat TTE ditafsirkan TtdModal; galat lain diberi pesan CPPT. ──
const KODE_TTE = ['pin_wajib', 'pin_salah', 'ttd_terkunci', 'ttd_belum_aktif']
type Tindakan = 'sahkan' | 'perbaiki' | 'adendum'
const tindakan = ref<Tindakan>('sahkan')
const dialogTtd = ref(false)
const PESAN_SELESAI: Record<Tindakan, string> = {
  sahkan: 'CPPT disahkan. Catatan terkunci dan tampil di linimasa petugas lain.',
  perbaiki: 'Perbaikan CPPT disimpan dan ditandatangani ulang. Isi lama tersimpan sebagai versi.',
  adendum: 'Adendum ditandatangani dan ditambahkan.',
}
function mintaTtd(t: Tindakan) {
  galat.value = null
  tindakan.value = t
  dialogTtd.value = true
}
async function tandatangani(pin: string | null, pernyataan: boolean) {
  const ttd = { pin, pernyataan_disetujui: pernyataan }
  try {
    if (tindakan.value === 'sahkan') await sahkanCppt(e.value.id, etag.value, ttd)
    else if (tindakan.value === 'perbaiki') await ubahCppt(e.value.id, etag.value, { ...isiSoap(f), alasan_perubahan: alasan.value.trim(), ...ttd })
    else await adendumCppt(e.value.id, etag.value, { isi: isiAdendum.value.trim(), alasan: alasan.value.trim(), ...ttd })
  } catch (x) {
    if (x instanceof ApiError && x.kode === 'batas_perbaikan_lewat') {
      // B-14: perbaikan tidak lagi diterima; pindah ke adendum dengan alasan yang sudah ditulis.
      batasLewat.value = true
      mode.value = 'adendum'
      void segarkan()
      return
    }
    // Sama dengan useTandaTangan: status TTE di store ikut berubah sebelum galat ditafsirkan modal.
    if (x instanceof ApiError && x.kode === 'pin_wajib') perbaruiStatusTtd({ sesi_berlaku_sampai: null })
    if (x instanceof ApiError && x.kode === 'ttd_terkunci') {
      perbaruiStatusTtd({ terkunci_sampai: new Date(Date.now() + (x.cobaLagiDetik ?? 900) * 1000).toISOString(), sesi_berlaku_sampai: null })
    }
    if (x instanceof ApiError && !KODE_TTE.includes(x.kode)) throw new ApiError(x.status, x.kode, pesanCppt(x), x.rincian, x.cobaLagiDetik)
    throw x
  }
  tampilkanToast(PESAN_SELESAI[tindakan.value], 'ok')
  mode.value = 'baca'
  batasLewat.value = false
  void segarkan()
}
const ringkasan = computed(() => {
  const isi = tindakan.value === 'adendum' ? isiAdendum.value : BAGIAN.map(([h, k]) => (tindakan.value === 'perbaiki' ? f[k] : e.value.isi[k]) && `${h}: ${tindakan.value === 'perbaiki' ? f[k] : e.value.isi[k]}`).filter(Boolean).join(' · ')
  return isi.length > 240 ? `${isi.slice(0, 240)}…` : isi
})
const judulTtd = computed(() => {
  const dasar = `CPPT ${(LABEL_PROFESI[e.value.profesi] ?? e.value.profesi).toLowerCase()} · ${formatTanggalWaktu(e.value.dibuat_pada)} · ${props.identitas}`
  return tindakan.value === 'adendum' ? `Adendum ${dasar}` : tindakan.value === 'perbaiki' ? `Perbaikan ${dasar}` : dasar
})

const bisaKirimPerbaikan = computed(() => adaIsi(f) && alasan.value.trim().length >= 5)
const bisaKirimAdendum = computed(() => isiAdendum.value.trim().length > 0 && alasan.value.trim().length >= 5)
const sedangSah = computed(() => e.value.status === 'sah')
</script>

<template>
  <article class="entri" :class="{ draf: e.status === 'draf' }" :aria-labelledby="`judul-cppt-${e.id}`">
    <header class="kepala">
      <h3 :id="`judul-cppt-${e.id}`" class="judul">
        {{ LABEL_PROFESI[e.profesi] ?? e.profesi }} · {{ e.penulis.nama }}
      </h3>
      <p class="meta">
        <time :datetime="e.dibuat_pada">{{ formatTanggalWaktu(e.dibuat_pada) }}</time> · {{ e.unit.nama }} · {{ LABEL_TATANAN[e.tatanan] }}
      </p>
      <div class="lencana">
        <Lencana :jenis="sedangSah ? 'ok' : 'info'">{{ sedangSah ? 'Sah' : 'Draf' }}</Lencana>
        <Lencana v-if="e.label" jenis="peringatan">Berlabel sensitif</Lencana>
        <Lencana v-if="e.jumlah_versi" jenis="netral">Diperbaiki {{ e.jumlah_versi }}×</Lencana>
      </div>
    </header>

    <dl v-if="mode !== 'ubah'" class="soap">
      <template v-for="[h, k] in BAGIAN" :key="k">
        <template v-if="e.isi[k]">
          <dt><abbr :title="k[0].toUpperCase() + k.slice(1)">{{ h }}</abbr></dt>
          <dd>{{ e.isi[k] }}</dd>
        </template>
      </template>
    </dl>
    <p v-if="sedangSah" class="ket">
      Disahkan {{ formatTanggalWaktu(e.disahkan_pada) }}<template v-if="e.dapat.ubah && sisa">. Perbaikan masih bisa dalam {{ sisa }}</template>.
    </p>
    <p v-else class="ket">Draf hanya terlihat oleh Anda. Sahkan dengan PIN agar masuk linimasa petugas lain.</p>

    <section v-if="e.adendum.length" class="adendum" :aria-label="`Adendum ${e.adendum.length}`">
      <h4>Adendum</h4>
      <ol>
        <li v-for="a in e.adendum" :key="a.id">
          <p>{{ a.isi }}</p>
          <p class="ket">{{ a.penulis.nama }}, ditandatangani {{ formatTanggalWaktu(a.disahkan_pada) }}. Alasan: {{ a.alasan }}</p>
        </li>
      </ol>
    </section>

    <form v-if="mode === 'ubah'" class="formulir" @submit.prevent>
      <Pesan v-if="sedangSah" jenis="info">Perbaikan catatan sah butuh alasan dan tanda tangan ulang. Isi lama tersimpan sebagai versi.</Pesan>
      <FormSoap v-model="f" />
      <KolomIsian v-if="sedangSah" v-slot="a" label="Alasan perubahan" wajib petunjuk="Minimal 5 karakter. Tercatat bersama versi lama.">
        <textarea v-bind="a" v-model="alasan" class="isian" rows="2" maxlength="500"></textarea>
      </KolomIsian>
    </form>
    <form v-else-if="mode === 'adendum'" class="formulir" @submit.prevent>
      <Pesan v-if="batasLewat" jenis="peringatan">Batas perbaikan 2 × 24 jam sudah lewat, jadi isi catatan tidak bisa diubah lagi. Tulis koreksinya sebagai adendum bertanda tangan.</Pesan>
      <KolomIsian v-slot="a" label="Isi adendum" wajib petunjuk="Isi catatan asal tidak berubah; adendum tampil di bawahnya.">
        <textarea v-bind="a" v-model="isiAdendum" class="isian" rows="3" maxlength="4000"></textarea>
      </KolomIsian>
      <KolomIsian v-slot="a" label="Alasan adendum" wajib petunjuk="Minimal 5 karakter.">
        <textarea v-bind="a" v-model="alasan" class="isian" rows="2" maxlength="500"></textarea>
      </KolomIsian>
    </form>

    <div role="alert"><Pesan v-if="galat" jenis="error">{{ galat }}</Pesan></div>

    <div v-if="e.dapat.ubah || e.dapat.sahkan || e.dapat.adendum" class="aksi">
      <template v-if="mode === 'baca'">
        <Tombol v-if="e.dapat.ubah" @click="buka('ubah')">{{ sedangSah ? 'Perbaiki' : 'Ubah draf' }}</Tombol>
        <Tombol v-if="e.dapat.adendum" @click="buka('adendum')">Tambah adendum</Tombol>
        <template v-if="e.dapat.sahkan">
          <Tombol v-if="ttdAktif" varian="utama" @click="mintaTtd('sahkan')">Sahkan dengan PIN</Tombol>
          <RouterLink v-else to="/tanda-tangan" class="tombol">Aktifkan TTE untuk mengesahkan</RouterLink>
        </template>
      </template>
      <template v-else>
        <Tombol :disabled="simpanDraf.isPending.value || undefined" @click="(mode = 'baca'), (batasLewat = false)">Batal</Tombol>
        <Tombol v-if="mode === 'ubah' && !sedangSah" varian="utama" :disabled="!adaIsi(f) || undefined" :sibuk="simpanDraf.isPending.value" @click="simpanDraf.mutate()">Simpan draf</Tombol>
        <template v-else-if="ttdAktif">
          <Tombol v-if="mode === 'ubah'" varian="utama" :disabled="!bisaKirimPerbaikan || undefined" @click="mintaTtd('perbaiki')">Simpan perbaikan dan tandatangani</Tombol>
          <Tombol v-else varian="utama" :disabled="!bisaKirimAdendum || undefined" @click="mintaTtd('adendum')">Tandatangani adendum</Tombol>
        </template>
        <RouterLink v-else to="/tanda-tangan" class="tombol">Aktifkan TTE untuk menandatangani</RouterLink>
      </template>
    </div>

    <TtdModal
      v-model:open="dialogTtd"
      :objek="[{ id: e.id, judul: judulTtd, ringkasan, peringatan: null }]"
      :pengguna="pengguna"
      :sesi-berlaku-sampai="sesi.pengguna?.ttd?.sesi_berlaku_sampai ?? null"
      :tandatangani="tandatangani"
    />
  </article>
</template>

<style scoped>
.entri {
  display: grid;
  gap: var(--space-3);
  padding: var(--space-4);
  border: 1px solid var(--border);
  border-radius: var(--radius-card);
  background: var(--surface);
  min-width: 0;
}
.entri.draf {
  border-style: dashed;
}
.kepala {
  display: grid;
  gap: var(--space-1);
}
.judul {
  margin: 0;
  font-size: 15px;
}
.meta,
.ket {
  margin: 0;
  font-size: 12.5px;
  color: var(--muted);
}
.lencana {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
.soap {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: var(--space-2) var(--space-3);
  margin: 0;
}
.soap dt {
  font-weight: 600;
}
.soap abbr {
  text-decoration: none;
}
.soap dd {
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.adendum h4 {
  margin: 0 0 var(--space-2);
  font-size: 13px;
}
.adendum ol {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding-left: var(--space-4);
}
.adendum p {
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.formulir {
  display: grid;
  gap: var(--space-3);
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
}
</style>
