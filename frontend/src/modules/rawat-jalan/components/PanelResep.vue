<script setup lang="ts">
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, nextTick, reactive, ref, useId, watch } from 'vue'
import Lencana from '@/design-system/components/Lencana.vue'
import PopoverPencarian, { type ItemPencarian } from '@/design-system/components/PopoverPencarian.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import TtdModal from '@/design-system/components/TtdModal.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { useSesiStore } from '@/app/sesi'
import { daftarBarang } from '@/modules/formularium/api'
import { perbaruiStatusTtd } from '@/modules/ttd/ttd'
import { ApiError } from '@/shared/api/klien'
import { batalkanResep, buatResep, daftarDepo, daftarResepKunjungan, ringkasItemResep, sahkanResep, ubahResep, type IsiItemResep, type Resep } from '@/shared/api/resep'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { KUNCI_WORKLIST, LABEL_PERINGATAN_RESEP, pesanResep } from '../rawatJalan'

/**
 * E-resep rawat jalan di panel Pemeriksaan (tiket 2.17; FR-07-06, FR-07-07, FR-02-10). Dokter menulis draf, server memberi
 * peringatan (alergi, duplikasi zat aktif, di luar formularium); setiap peringatan wajib diberi alasan sebelum sahkan (aturan
 * UX 5.1), lalu sahkan dengan PIN T1 milik akun sendiri. Resep sah masuk antrean farmasi; resep sah tidak diubah, hanya batal.
 * `identitas` = nama + No. RM + tanggal lahir, tampil di modal tanda tangan (5.1 butir 9).
 */
const props = defineProps<{ kunjunganId: string; identitas: string; bisaTulis: boolean }>()
const qc = useQueryClient()
const sesi = useSesiStore()
const idJudul = useId()
const kunci = computed(() => KUNCI_WORKLIST.resep(props.kunjunganId))
const LABEL_STATUS = { draf: 'Draf', sah: 'Sah', batal: 'Dibatalkan' } as const
const NADA_STATUS = { draf: 'info', sah: 'ok', batal: 'netral' } as const

const daftar = useQuery({ queryKey: kunci, queryFn: () => daftarResepKunjungan(props.kunjunganId) })
const segarkan = () => void qc.invalidateQueries({ queryKey: kunci.value })

// ── Resep baru ──
type Butir = Omit<IsiItemResep, 'jumlah' | 'jumlah_per_pakai' | 'frekuensi_per_hari' | 'hari'> & { nama: string; satuan: string; frekuensi_per_hari: number | ''; jumlah_per_pakai: number | ''; hari: number | ''; jumlah: number | '' }
const depo = useQuery({ queryKey: ['farmasi', 'depo'], queryFn: daftarDepo, enabled: computed(() => props.bisaTulis), staleTime: 300_000 })
const depoId = ref('')
watch(() => depo.data.value, (d) => d?.length === 1 && !depoId.value && (depoId.value = d[0]!.id), { immediate: true })
const butir = ref<Butir[]>([])
const racikan = ref(false)
const catatan = ref('')

const cariBuka = ref(false)
const kueri = ref('')
const hasil = ref<ItemPencarian[]>([])
const statusCari = ref('Ketik minimal 2 huruf kode, nama, atau zat aktif obat.')
const galatCari = ref<string | null>(null)
const satuan = new Map<string, string>()
let jeda: ReturnType<typeof setTimeout> | undefined
watch(kueri, (q) => {
  clearTimeout(jeda)
  hasil.value = []
  if (q.trim().length < 2) return void (statusCari.value = 'Ketik minimal 2 huruf kode, nama, atau zat aktif obat.')
  statusCari.value = 'Mencari…'
  jeda = setTimeout(async () => {
    try {
      const barang = (await daftarBarang({ cari: q.trim(), status: 'aktif' }, null, 20)).data.filter((b) => b.jenis === 'obat' || b.jenis === 'bmhp')
      if (kueri.value !== q) return
      const sudah = new Set(butir.value.map((b) => b.barang_id))
      barang.forEach((b) => satuan.set(b.id, b.satuan_terkecil))
      hasil.value = barang.filter((b) => !sudah.has(b.id)).map((b) => ({ kode: b.id, label: [b.nama, b.kekuatan].filter(Boolean).join(' '), pil: b.kode }))
      statusCari.value = hasil.value.length ? `${hasil.value.length} obat ditemukan.` : 'Tidak ada obat aktif yang cocok di katalog RS.'
      galatCari.value = null
    } catch (e) {
      statusCari.value = 'Pencarian gagal.'
      galatCari.value = e instanceof ApiError && e.status === 403 ? 'Pemilih obat memakai izin lihat Formularium; minta admin memberikannya ke peran Anda.' : pesanResep(e)
      cariBuka.value = false
    }
  }, 250)
})
function pilihObat(i: ItemPencarian) {
  butir.value = [...butir.value, { barang_id: i.kode, nama: i.label, satuan: satuan.get(i.kode) ?? '', dosis: null, frekuensi_per_hari: 3, jumlah_per_pakai: 1, hari: 3, jumlah: '', rute: null, aturan_pakai: null, catatan: null }]
  kirim.reset()
}
const hapusButir = (idx: number) => (butir.value = butir.value.filter((_, n) => n !== idx))
const teks = (v: string | null) => v?.trim() || null
const bolehKirim = computed(() => Boolean(depoId.value) && butir.value.length > 0 && butir.value.every((b) => Number(b.frekuensi_per_hari) >= 1 && Number(b.jumlah_per_pakai) > 0 && Number(b.hari) >= 1))
const kirim = useMutation({
  mutationFn: () =>
    buatResep(props.kunjunganId, {
      depo_id: depoId.value,
      racikan: racikan.value,
      catatan: teks(catatan.value),
      item: butir.value.map((b) => ({
        barang_id: b.barang_id, dosis: teks(b.dosis), frekuensi_per_hari: Number(b.frekuensi_per_hari), jumlah_per_pakai: Number(b.jumlah_per_pakai),
        hari: Number(b.hari), jumlah: b.jumlah === '' ? null : Number(b.jumlah), rute: teks(b.rute), aturan_pakai: teks(b.aturan_pakai), catatan: null,
      })),
    }),
  onSuccess: ({ data }) => {
    tampilkanToast(data.peringatan.length ? `Draf ${data.no_resep} tersimpan dengan ${data.peringatan.length} peringatan. Beri alasan sebelum sahkan.` : `Draf ${data.no_resep} tersimpan. Sahkan agar masuk antrean farmasi.`, data.peringatan.length ? 'peringatan' : 'ok')
    butir.value = []
    racikan.value = false
    catatan.value = ''
    segarkan()
  },
})

// ── Draf: hapus butir (PATCH mengganti seluruh daftar butir) ──
const ubah = useMutation({
  mutationFn: (a: { r: Resep; itemId: string }) =>
    ubahResep(a.r, {
      item: a.r.item.filter((i) => i.id !== a.itemId).map((i) => ({ barang_id: i.barang.id, dosis: i.dosis, frekuensi_per_hari: i.frekuensi_per_hari, jumlah_per_pakai: i.jumlah_per_pakai, hari: i.hari, jumlah: i.jumlah, rute: i.rute, aturan_pakai: i.aturan_pakai, catatan: i.catatan })),
    }),
  onSuccess: segarkan,
  onError: segarkan,
})

// ── Tanggapan peringatan + sahkan (PIN T1). Alasan per kunci peringatan, hanya di memori. ──
const tanggapan = reactive<Record<string, string>>({})
const belumDitanggapi = (r: Resep) => r.peringatan.filter((p) => (tanggapan[p.kunci] ?? '').trim().length < 5).length
const sasaranTtd = ref<Resep | null>(null)
const dialogTtd = ref(false)
const galatSahkan = ref<{ id: string; pesan: string } | null>(null)
const KODE_TTE = ['pin_wajib', 'pin_salah', 'ttd_terkunci', 'ttd_belum_aktif']
const pengguna = computed(() => ({ id: sesi.pengguna?.id ?? '', nama: sesi.pengguna?.pegawai?.nama ?? sesi.pengguna?.nama_pengguna ?? '' }))
function mintaSahkan(r: Resep) {
  galatSahkan.value = null
  sasaranTtd.value = r
  dialogTtd.value = true
}
async function tandatangani(pin: string | null, pernyataan: boolean) {
  const r = sasaranTtd.value!
  let sah: Resep
  try {
    sah = (await sahkanResep(r, { pin, pernyataan_disetujui: pernyataan, tanggapan: r.peringatan.map((p) => ({ kunci: p.kunci, alasan: (tanggapan[p.kunci] ?? '').trim() })) })).data
  } catch (x) {
    // Sama dengan useTandaTangan: status TTE di store ikut berubah sebelum galat ditafsirkan modal.
    if (x instanceof ApiError && x.kode === 'pin_wajib') perbaruiStatusTtd({ sesi_berlaku_sampai: null })
    if (x instanceof ApiError && x.kode === 'ttd_terkunci') perbaruiStatusTtd({ terkunci_sampai: new Date(Date.now() + (x.cobaLagiDetik ?? 900) * 1000).toISOString(), sesi_berlaku_sampai: null })
    if (x instanceof ApiError && !KODE_TTE.includes(x.kode)) {
      // Galat resep (peringatan baru, SIP, bukan dokter kunjungan) tampil di kartu resepnya; modal ditutup.
      galatSahkan.value = { id: r.id, pesan: pesanResep(x) }
      dialogTtd.value = false
      segarkan()
      return
    }
    throw x
  }
  tampilkanToast(sah.antrean ? `Resep ${sah.no_resep} sah. Pasien masuk antrean farmasi ${sah.antrean.nomor_tampil}.` : `Resep ${sah.no_resep} sah.`, 'ok')
  segarkan()
}
const objekTtd = computed(() => {
  const r = sasaranTtd.value
  if (!r) return []
  const ringkasan = r.item.map(ringkasItemResep).join('; ')
  const peringatan = r.peringatan.length ? r.peringatan.map((p) => `${LABEL_PERINGATAN_RESEP[p.jenis]}: ${p.pesan} Alasan: ${(tanggapan[p.kunci] ?? '').trim()}`).join(' ') : null
  return [{ id: r.id, judul: `Resep ${r.no_resep} · ${props.identitas}`, ringkasan: ringkasan.length > 240 ? `${ringkasan.slice(0, 240)}…` : ringkasan, peringatan }]
})

// ── Pembatalan ──
const sasaranBatal = ref<Resep | null>(null)
const alasanBatal = ref('')
const elAlasanBatal = ref<HTMLTextAreaElement | null>(null)
let asalBatal: HTMLElement | null = null
function mulaiBatal(r: Resep) {
  asalBatal = document.activeElement instanceof HTMLElement ? document.activeElement : null
  sasaranBatal.value = r
  alasanBatal.value = ''
  batal.reset()
  void nextTick(() => elAlasanBatal.value?.focus())
}
function tutupBatal() {
  sasaranBatal.value = null
  if (asalBatal?.isConnected) asalBatal.focus()
}
const batal = useMutation({
  mutationFn: () => batalkanResep(sasaranBatal.value!.id, alasanBatal.value.trim()),
  onSuccess: (r) => {
    tampilkanToast(`Resep ${r.no_resep} dibatalkan.`, 'ok')
    tutupBatal()
    segarkan()
  },
})
</script>

<template>
  <section :aria-labelledby="idJudul">
    <h3 :id="idJudul">Resep</h3>
    <p v-if="daftar.isPending.value" class="ket" role="status">Memuat resep…</p>
    <div role="alert">
      <Pesan v-if="daftar.isError.value" jenis="error">Resep tidak dapat dimuat: {{ pesanResep(daftar.error.value) }}</Pesan>
    </div>
    <p v-if="daftar.isSuccess.value && !daftar.data.value?.length" class="ket">Belum ada resep di kunjungan ini.</p>
    <ul v-else-if="daftar.isSuccess.value" class="daftar">
      <li v-for="r in daftar.data.value" :key="r.id" :class="{ redup: r.status === 'batal' }">
        <p class="baris-judul">
          <b>{{ r.no_resep }}</b>
          <Lencana :jenis="NADA_STATUS[r.status]">{{ LABEL_STATUS[r.status] }}</Lencana>
          <Lencana v-if="r.racikan" jenis="info">Racikan</Lencana>
          <span v-if="r.antrean" class="antrean">Antrean farmasi <code>{{ r.antrean.nomor_tampil }}</code></span>
        </p>
        <span class="ket">{{ r.depo.nama }} · ditulis {{ r.penulis?.nama ?? 'sistem' }}, {{ formatTanggalWaktu(r.dibuat_pada) }}<template v-if="r.disahkan_pada"> · disahkan {{ r.dokter?.nama }}, {{ formatTanggalWaktu(r.disahkan_pada) }}</template></span>
        <ul class="butir" :aria-label="`Obat resep ${r.no_resep}`">
          <li v-for="i in r.item" :key="i.id">
            <span>{{ ringkasItemResep(i) }}</span>
            <Tombol v-if="r.dapat.ubah && r.item.length > 1" varian="tautan" :sibuk="ubah.isPending.value && ubah.variables.value?.itemId === i.id" :aria-label="`Hapus ${i.barang.nama} dari resep ${r.no_resep}`" @click="ubah.mutate({ r, itemId: i.id })">Hapus</Tombol>
          </li>
        </ul>
        <p v-if="r.catatan" class="ket">Catatan: {{ r.catatan }}</p>

        <div v-if="r.peringatan.length" class="peringatan">
          <h4>Peringatan ({{ r.peringatan.length }})</h4>
          <div v-for="p in r.peringatan" :key="p.kunci" class="satu-peringatan">
            <p>
              <Lencana :jenis="p.jenis === 'alergi' ? 'bahaya' : 'peringatan'">{{ LABEL_PERINGATAN_RESEP[p.jenis] }}<template v-if="p.tingkat">, {{ p.tingkat }}</template></Lencana>
              {{ p.pesan }}
            </p>
            <p v-if="p.tanggapan" class="ket">Tetap diresepkan: {{ p.tanggapan.alasan }} ({{ p.tanggapan.oleh?.nama ?? 'sistem' }})</p>
            <KolomIsian v-else-if="r.dapat.sahkan" v-slot="k" label="Alasan tetap meresepkan" wajib petunjuk="Minimal 5 karakter. Disimpan bersama resep saat disahkan.">
              <textarea v-bind="k" v-model="tanggapan[p.kunci]" class="isian" rows="2" maxlength="500"></textarea>
            </KolomIsian>
          </div>
        </div>
        <p v-if="r.status === 'batal'" class="ket">Alasan batal: {{ r.alasan_batal }}</p>

        <div role="alert"><Pesan v-if="galatSahkan?.id === r.id" jenis="error">{{ galatSahkan.pesan }}</Pesan></div>
        <Pesan v-if="ubah.isError.value && ubah.variables.value?.r.id === r.id" jenis="error">{{ pesanResep(ubah.error.value) }}</Pesan>
        <div v-if="r.dapat.sahkan || r.dapat.batal" class="aksi">
          <span v-if="r.dapat.sahkan && belumDitanggapi(r)" class="ket">{{ belumDitanggapi(r) }} peringatan belum diberi alasan.</span>
          <Tombol v-if="r.dapat.batal" :aria-label="`Batalkan resep ${r.no_resep}`" @click="mulaiBatal(r)">Batalkan</Tombol>
          <Tombol v-if="r.dapat.sahkan" varian="utama" :disabled="belumDitanggapi(r) > 0 || undefined" :aria-label="`Sahkan resep ${r.no_resep}`" @click="mintaSahkan(r)">Sahkan resep</Tombol>
        </div>
      </li>
    </ul>

    <form v-if="sasaranBatal" class="form" @submit.prevent="batal.mutate()">
      <KolomIsian v-slot="k" :label="`Alasan membatalkan resep ${sasaranBatal.no_resep}`" wajib petunjuk="Minimal 5 karakter. Resep tidak dihapus; alasan dan pelakunya tersimpan. Antrean farmasi ikut batal bila tidak ada resep sah lain hari ini." :error="batal.isError.value ? pesanResep(batal.error.value) : undefined">
        <textarea v-bind="k" ref="elAlasanBatal" v-model="alasanBatal" class="isian" rows="2" maxlength="500"></textarea>
      </KolomIsian>
      <div class="aksi">
        <Tombol @click="tutupBatal">Tidak jadi</Tombol>
        <Tombol type="submit" varian="bahaya" :disabled="alasanBatal.trim().length < 5 || undefined" :sibuk="batal.isPending.value">Batalkan resep</Tombol>
      </div>
    </form>

    <form v-if="bisaTulis" class="form baru" :aria-labelledby="`${idJudul}-baru`" @submit.prevent="kirim.mutate()">
      <h4 :id="`${idJudul}-baru`">Resep baru</h4>
      <div class="baris-isian">
        <KolomIsian v-slot="k" label="Depo farmasi" wajib :error="depo.isError.value ? `Daftar depo tidak dapat dimuat: ${pesanResep(depo.error.value)}` : undefined">
          <select v-bind="k" v-model="depoId" class="isian">
            <option value="" disabled>Pilih depo…</option>
            <option v-for="d in depo.data.value ?? []" :key="d.id" :value="d.id">{{ d.nama }}</option>
          </select>
        </KolomIsian>
        <label class="centang"><input v-model="racikan" type="checkbox" /> Racikan (ditandai di antrean farmasi)</label>
      </div>

      <div>
        <PopoverPencarian v-model:open="cariBuka" v-model:kueri="kueri" label="Tambah obat" uraian="katalog obat RS" label-isian="Cari obat" placeholder="Kode, nama, atau zat aktif" :item="hasil" :status="statusCari" ikon="tambah" @pilih="pilihObat" />
        <div role="alert"><Pesan v-if="galatCari" jenis="error">{{ galatCari }}</Pesan></div>
      </div>
      <p v-if="!butir.length" class="ket">Belum ada obat dipilih.</p>
      <fieldset v-for="(b, n) in butir" :key="b.barang_id" class="butir-baru">
        <legend>{{ b.nama }}</legend>
        <div class="grid-butir">
          <KolomIsian v-slot="k" label="Frekuensi per hari" wajib>
            <input v-bind="k" v-model.number="b.frekuensi_per_hari" type="number" min="1" max="24" class="isian" />
          </KolomIsian>
          <KolomIsian v-slot="k" :label="`Jumlah per pakai${b.satuan ? ` (${b.satuan})` : ''}`" wajib>
            <input v-bind="k" v-model.number="b.jumlah_per_pakai" type="number" min="0.01" step="any" class="isian" />
          </KolomIsian>
          <KolomIsian v-slot="k" label="Lama (hari)" wajib>
            <input v-bind="k" v-model.number="b.hari" type="number" min="1" max="365" class="isian" />
          </KolomIsian>
          <KolomIsian v-slot="k" label="Jumlah total" petunjuk="Kosong: dihitung otomatis (frekuensi × jumlah per pakai × hari).">
            <input v-bind="k" v-model.number="b.jumlah" type="number" min="0.01" step="any" class="isian" />
          </KolomIsian>
          <KolomIsian v-slot="k" label="Dosis">
            <input v-bind="k" v-model="b.dosis" type="text" maxlength="100" class="isian" />
          </KolomIsian>
          <KolomIsian v-slot="k" label="Rute">
            <input v-bind="k" v-model="b.rute" type="text" maxlength="50" class="isian" />
          </KolomIsian>
          <KolomIsian v-slot="k" label="Aturan pakai" petunjuk="Mis. sesudah makan.">
            <input v-bind="k" v-model="b.aturan_pakai" type="text" maxlength="200" class="isian" />
          </KolomIsian>
        </div>
        <Tombol varian="tautan" :aria-label="`Hapus ${b.nama} dari resep baru`" @click="hapusButir(n)">Hapus obat</Tombol>
      </fieldset>
      <KolomIsian v-slot="k" label="Catatan untuk farmasi">
        <textarea v-bind="k" v-model="catatan" class="isian" rows="2" maxlength="1000"></textarea>
      </KolomIsian>
      <div role="alert"><Pesan v-if="kirim.isError.value" jenis="error">{{ pesanResep(kirim.error.value) }}</Pesan></div>
      <div class="aksi">
        <Tombol type="submit" varian="utama" :disabled="!bolehKirim || undefined" :sibuk="kirim.isPending.value">Simpan draf resep</Tombol>
      </div>
    </form>

    <TtdModal v-model:open="dialogTtd" :objek="objekTtd" :pengguna="pengguna" :sesi-berlaku-sampai="sesi.pengguna?.ttd?.sesi_berlaku_sampai ?? null" :tandatangani="tandatangani" />
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
.butir {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}
.daftar > li {
  display: grid;
  gap: var(--space-2);
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.butir li {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-2);
}
.redup {
  color: var(--muted);
}
.baris-judul {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
.antrean {
  margin-left: auto;
}
.peringatan {
  display: grid;
  gap: var(--space-2);
  padding-top: var(--space-2);
  border-top: 1px solid var(--border);
}
.satu-peringatan {
  display: grid;
  gap: var(--space-2);
}
.form {
  display: grid;
  gap: var(--space-3);
  margin-top: var(--space-3);
}
.baru {
  padding-top: var(--space-3);
  border-top: 1px solid var(--border);
}
.baris-isian {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: var(--space-4);
}
.centang {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 44px;
}
.butir-baru {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.butir-baru legend {
  padding: 0 var(--space-1);
  font-weight: 600;
}
.grid-butir {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: var(--space-3);
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  align-items: center;
  gap: var(--space-2);
}
</style>
