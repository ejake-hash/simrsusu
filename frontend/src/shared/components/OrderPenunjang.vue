<script setup lang="ts">
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, nextTick, ref, useId, watch } from 'vue'
import Lencana from '@/design-system/components/Lencana.vue'
import PopoverPencarian, { type ItemPencarian } from '@/design-system/components/PopoverPencarian.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { ApiError } from '@/shared/api/klien'
import { batalkanPermintaan, buatPermintaan, cariKatalog, daftarPermintaanEpisode, LABEL_STATUS_ITEM, NADA_STATUS_ITEM, type JenisPermintaan, type PermintaanPemeriksaan, type PrioritasPermintaan } from '@/shared/api/permintaanPemeriksaan'
import { formatTanggalWaktu } from '@/shared/utils/waktu'

/**
 * Order penunjang bersama (tiket 2.16; FR-00-08, FR-07-27, 7.00.5): satu komponen untuk semua pos (poli sekarang, IGD dan rawat
 * inap menyusul) tanpa logika khusus pos. Order terikat kunjungan dan episode saat dipesan (server). Daftar = semua order
 * episode ini. Pemanggil menentukan `bisaPesan` (izin bagian order pos itu + kunjungan sedang dilayani).
 */
const props = defineProps<{ kunjunganId: string; episodeId: string; bisaPesan: boolean }>()
const qc = useQueryClient()
const idJudul = useId()
const kunci = computed(() => ['permintaan-pemeriksaan', 'episode', props.episodeId])
const LABEL_JENIS: Record<JenisPermintaan, string> = { lab: 'Laboratorium', rad: 'Radiologi' }

const daftar = useQuery({ queryKey: kunci, queryFn: () => daftarPermintaanEpisode(props.episodeId) })

function pesanGalat(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Terjadi kesalahan. Coba lagi.'
  if (e.status === 403 && e.kode === 'akses_ditolak') return `${e.message} Katalog pemeriksaan memakai izin lihat Buku tarif; minta admin memberikannya ke peran Anda.`
  if (e.kode === 'validasi_gagal' && e.rincian.some((r) => r.kolom?.startsWith('item.'))) return 'Sebagian pemeriksaan tidak aktif lagi atau bukan jenis ini. Hapus lalu pilih ulang dari katalog.'
  if (e.kode === 'status_tidak_sesuai') return `${e.message} Muat ulang untuk melihat keadaan terbaru.`
  return e.message
}

// ── Formulir order ──
const jenis = ref<JenisPermintaan>('lab')
const prioritas = ref<PrioritasPermintaan>('rutin')
const dipilih = ref<ItemPencarian[]>([])
const indikasi = ref('')
const catatan = ref('')
const alasanGanda = ref('')
watch(jenis, () => {
  dipilih.value = []
  kirim.reset()
})

const cariBuka = ref(false)
const kueri = ref('')
const hasil = ref<ItemPencarian[]>([])
const statusCari = ref('Ketik minimal 2 huruf kode atau nama pemeriksaan.')
const galatCari = ref<string | null>(null)
let jeda: ReturnType<typeof setTimeout> | undefined
watch([kueri, jenis], ([q, j]) => {
  clearTimeout(jeda)
  hasil.value = []
  if (q.trim().length < 2) return void (statusCari.value = 'Ketik minimal 2 huruf kode atau nama pemeriksaan.')
  statusCari.value = 'Mencari…'
  jeda = setTimeout(async () => {
    try {
      const butir = await cariKatalog(j, q.trim())
      if (kueri.value !== q || jenis.value !== j) return
      const sudah = new Set(dipilih.value.map((d) => d.kode))
      hasil.value = butir.filter((b) => !sudah.has(b.id)).map((b) => ({ kode: b.id, label: b.nama, pil: b.kode }))
      statusCari.value = hasil.value.length ? `${hasil.value.length} pemeriksaan ditemukan.` : `Tidak ada pemeriksaan ${LABEL_JENIS[j].toLowerCase()} yang cocok di katalog RS.`
      galatCari.value = null
    } catch (e) {
      statusCari.value = 'Pencarian gagal.'
      galatCari.value = pesanGalat(e)
      cariBuka.value = false
    }
  }, 250)
})
function hapus(i: ItemPencarian) {
  dipilih.value = dipilih.value.filter((d) => d.kode !== i.kode)
  kirim.reset()
}
function pilih(i: ItemPencarian) {
  if (!dipilih.value.some((d) => d.kode === i.kode)) dipilih.value = [...dipilih.value, i]
  kirim.reset()
}

const kirim = useMutation({
  mutationFn: (denganAlasan: boolean) =>
    buatPermintaan(props.kunjunganId, {
      jenis: jenis.value,
      prioritas: prioritas.value,
      diagnosis_kerja: indikasi.value.trim(),
      catatan: catatan.value.trim() || null,
      alasan_ganda: denganAlasan ? alasanGanda.value.trim() : null,
      item: dipilih.value.map((d) => ({ tarif_id: d.kode })),
    }),
  onSuccess: (p) => {
    tampilkanToast(`Order ${LABEL_JENIS[p.jenis].toLowerCase()} terkirim: ${p.item.length} pemeriksaan.`, 'ok')
    dipilih.value = []
    indikasi.value = ''
    catatan.value = ''
    alasanGanda.value = ''
    prioritas.value = 'rutin'
    void qc.invalidateQueries({ queryKey: kunci.value })
  },
})
// Order ganda (FR-07-27): server menyebut butir yang sudah dipesan hari ini; dokter memutuskan dengan alasan.
const ganda = computed(() => (kirim.error.value instanceof ApiError && kirim.error.value.kode === 'order_ganda' ? kirim.error.value.rincian : null))
const elAlasanGanda = ref<HTMLTextAreaElement | null>(null)
watch(ganda, (g) => g && void nextTick(() => elAlasanGanda.value?.focus()))
const bolehKirim = computed(() => dipilih.value.length > 0 && indikasi.value.trim().length >= 3)

// ── Pembatalan ──
const sasaran = ref<PermintaanPemeriksaan | null>(null)
const alasanBatal = ref('')
const elAlasanBatal = ref<HTMLTextAreaElement | null>(null)
let asalBatal: HTMLElement | null = null
function tutupBatal() {
  sasaran.value = null
  if (asalBatal?.isConnected) asalBatal.focus()
}
const batal = useMutation({
  mutationFn: () => batalkanPermintaan(sasaran.value!.id, alasanBatal.value.trim()),
  onSuccess: (p) => {
    tampilkanToast(`Order ${LABEL_JENIS[p.jenis].toLowerCase()} dibatalkan.`, 'ok')
    tutupBatal()
    void qc.invalidateQueries({ queryKey: kunci.value })
  },
})
function mulaiBatal(p: PermintaanPemeriksaan) {
  asalBatal = document.activeElement instanceof HTMLElement ? document.activeElement : null
  sasaran.value = p
  alasanBatal.value = ''
  batal.reset()
  void nextTick(() => elAlasanBatal.value?.focus())
}
const ringkasItem = (p: PermintaanPemeriksaan) => p.item.map((i) => i.nama).join(', ')
</script>

<template>
  <section :aria-labelledby="idJudul">
    <h3 :id="idJudul">Order penunjang</h3>
    <p v-if="daftar.isPending.value" class="ket" role="status">Memuat order…</p>
    <!-- Wadah alert selalu ada agar pesan yang muncul belakangan dibacakan (Pesan menyerahkan peran ARIA ke pemakai). -->
    <div role="alert">
      <Pesan v-if="daftar.isError.value" jenis="error">Order tidak dapat dimuat: {{ pesanGalat(daftar.error.value) }}</Pesan>
    </div>
    <p v-if="daftar.isSuccess.value && !daftar.data.value?.length" class="ket">Belum ada order laboratorium atau radiologi di episode ini.</p>
    <ul v-else-if="daftar.isSuccess.value" class="daftar">
      <li v-for="p in daftar.data.value" :key="p.id" :class="{ redup: p.status === 'batal' }">
        <div>
          <p class="baris-judul">
            <b>{{ LABEL_JENIS[p.jenis] }}</b>
            <Lencana v-if="p.prioritas === 'cito'" jenis="bahaya">Cito</Lencana>
            <Lencana v-if="p.status === 'batal'" jenis="netral">Dibatalkan</Lencana>
          </p>
          <p class="butir">
            <span v-for="(i, n) in p.item" :key="i.id">
              {{ i.nama }}<Lencana v-if="p.status !== 'batal' && i.status !== 'dipesan'" :jenis="NADA_STATUS_ITEM[i.status]" class="status-butir">{{ LABEL_STATUS_ITEM[i.status] }}</Lencana>{{ n < p.item.length - 1 ? ', ' : '' }}
            </span>
          </p>
          <span v-if="p.status === 'dipesan' && !p.dapat.batal && p.item.some((i) => i.status === 'diterima_lab')" class="ket">Sudah diterima lab, tidak bisa dibatalkan.</span>
          <span class="ket">Indikasi: {{ p.diagnosis_kerja }}</span>
          <span class="ket">{{ p.pemesan?.nama ?? 'Sistem' }} · {{ formatTanggalWaktu(p.dipesan_pada) }}</span>
          <span v-if="p.alasan_ganda" class="ket">Dipesan walau sudah ada order sama hari ini: {{ p.alasan_ganda }}</span>
          <span v-if="p.status === 'batal'" class="ket">Alasan batal: {{ p.alasan_batal }}</span>
        </div>
        <Tombol v-if="p.dapat.batal" :aria-label="`Batalkan order ${LABEL_JENIS[p.jenis].toLowerCase()} ${ringkasItem(p)}`" @click="mulaiBatal(p)">Batalkan</Tombol>
      </li>
    </ul>

    <form v-if="sasaran" class="form" @submit.prevent="batal.mutate()">
      <KolomIsian v-slot="k" :label="`Alasan membatalkan order ${ringkasItem(sasaran)}`" wajib petunjuk="Minimal 5 karakter. Order tidak dihapus; alasan dan pelakunya tersimpan." :error="batal.isError.value ? pesanGalat(batal.error.value) : undefined">
        <textarea v-bind="k" ref="elAlasanBatal" v-model="alasanBatal" class="isian" rows="2" maxlength="500"></textarea>
      </KolomIsian>
      <div class="aksi">
        <Tombol @click="tutupBatal">Tidak jadi</Tombol>
        <Tombol type="submit" varian="bahaya" :disabled="alasanBatal.trim().length < 5 || undefined" :sibuk="batal.isPending.value">Batalkan order</Tombol>
      </div>
    </form>

    <form v-if="bisaPesan" class="form order" :aria-labelledby="`${idJudul}-baru`" @submit.prevent="kirim.mutate(false)">
      <h4 :id="`${idJudul}-baru`">Order baru</h4>
      <div class="pilihan">
        <fieldset>
          <legend>Jenis</legend>
          <label v-for="(label, kode) in LABEL_JENIS" :key="kode"><input v-model="jenis" type="radio" name="jenis-order" :value="kode" /> {{ label }}</label>
        </fieldset>
        <fieldset>
          <legend>Prioritas</legend>
          <label><input v-model="prioritas" type="radio" name="prioritas-order" value="rutin" /> Rutin</label>
          <label><input v-model="prioritas" type="radio" name="prioritas-order" value="cito" /> Cito</label>
        </fieldset>
      </div>

      <div>
        <PopoverPencarian v-model:open="cariBuka" v-model:kueri="kueri" label="Tambah pemeriksaan" :uraian="`katalog ${LABEL_JENIS[jenis].toLowerCase()} RS`" :label-isian="`Cari pemeriksaan ${LABEL_JENIS[jenis].toLowerCase()}`" placeholder="Kode atau nama pemeriksaan" :item="hasil" :status="statusCari" ikon="tambah" @pilih="pilih" />
        <div role="alert"><Pesan v-if="galatCari" jenis="error">{{ galatCari }}</Pesan></div>
        <p v-if="!dipilih.length" class="ket">Belum ada pemeriksaan dipilih.</p>
        <ul v-else class="dipilih" aria-label="Pemeriksaan dipilih">
          <li v-for="d in dipilih" :key="d.kode">
            <span><code>{{ d.pil }}</code> {{ d.label }}</span>
            <Tombol varian="tautan" :aria-label="`Hapus ${d.label} dari order`" @click="hapus(d)">Hapus</Tombol>
          </li>
        </ul>
      </div>

      <KolomIsian v-slot="k" label="Indikasi klinis" wajib petunjuk="Alasan klinis pemeriksaan, minimal 3 karakter. Dibaca petugas penunjang.">
        <textarea v-bind="k" v-model="indikasi" class="isian" rows="2" maxlength="500"></textarea>
      </KolomIsian>
      <KolomIsian v-slot="k" label="Catatan untuk penunjang">
        <textarea v-bind="k" v-model="catatan" class="isian" rows="2" maxlength="1000"></textarea>
      </KolomIsian>

      <div role="alert">
        <Pesan v-if="ganda" jenis="peringatan">
          Sudah dipesan untuk pasien ini hari ini:
          <ul>
            <li v-for="r in ganda" :key="String(r.tarif_id)">{{ r.nama }} ({{ formatTanggalWaktu(String(r.dipesan_pada)) }})</li>
          </ul>
          Hapus butir itu, atau isi alasan untuk tetap memesan.
        </Pesan>
        <Pesan v-else-if="kirim.isError.value" jenis="error">{{ pesanGalat(kirim.error.value) }}</Pesan>
      </div>
      <KolomIsian v-if="ganda" v-slot="k" label="Alasan tetap memesan" wajib petunjuk="Minimal 5 karakter, mis. sampel sebelumnya rusak.">
        <textarea v-bind="k" ref="elAlasanGanda" v-model="alasanGanda" class="isian" rows="2" maxlength="500"></textarea>
      </KolomIsian>

      <div class="aksi">
        <Tombol v-if="ganda" varian="utama" :disabled="!bolehKirim || alasanGanda.trim().length < 5 || undefined" :sibuk="kirim.isPending.value" @click="kirim.mutate(true)">Tetap kirim order</Tombol>
        <Tombol v-else type="submit" varian="utama" :disabled="!bolehKirim || undefined" :sibuk="kirim.isPending.value">Kirim order {{ LABEL_JENIS[jenis].toLowerCase() }}</Tombol>
      </div>
    </form>
  </section>
</template>

<style scoped>
h3,
h4 {
  margin: 0 0 var(--space-2);
}
p {
  margin: 0;
}
.ket {
  display: block;
  font-size: 12px;
  color: var(--muted);
}
.daftar,
.dipilih {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}
.daftar li,
.dipilih li {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.dipilih li {
  align-items: center;
  padding-block: var(--space-1);
}
.redup {
  color: var(--muted);
}
.status-butir {
  margin-left: var(--space-1);
}
.baris-judul {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
.form {
  display: grid;
  gap: var(--space-3);
  margin-top: var(--space-3);
}
.order {
  padding-top: var(--space-3);
  border-top: 1px solid var(--border);
}
.pilihan {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-4);
}
fieldset {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-3);
  margin: 0;
  padding: 0;
  border: 0;
}
legend {
  float: left;
  margin-right: var(--space-2);
  font-weight: 600;
}
[role='alert'] ul {
  margin: var(--space-1) 0;
  padding-left: var(--space-4);
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
}
</style>
