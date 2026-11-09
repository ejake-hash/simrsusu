<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { hariIni } from '@/modules/formularium/usulanFormularium'
import { buatUsulan } from '@/modules/usulan/api'
import type { UsulanDataInduk } from '@/modules/usulan/tipe'
import { ApiError } from '@/shared/api/klien'
import { adalahButir, type AksiMatriks, bentukButirMatriks, LABEL_POS, labelSel, POS_ASAL, POS_TUJUAN } from '../matriks'
import type { AturanPerpindahan, PosAsalPerpindahan, PosTujuanPerpindahan, StatusAturan } from '../tipe'

/**
 * Usulan perubahan satu sel matriks perpindahan (tiket 2.26, kontrak v0.33.0): tambah sel baru (`sel` null), ubah izin
 * pemberi perintah/keterangan/status, atau nonaktifkan sel. Tidak ada tulis langsung: perubahan diverifikasi dan disetujui
 * orang lain lewat alur usulan data induk, lalu berlaku mulai tanggal yang dipilih.
 */
const props = defineProps<{ sel: AturanPerpindahan | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: UsulanDataInduk] }>()

const aksi = ref<AksiMatriks>('tambah')
const posAsal = ref<PosAsalPerpindahan | ''>('')
const posTujuan = ref<PosTujuanPerpindahan | ''>('')
const izin = ref('')
const keterangan = ref('')
const status = ref<StatusAturan>('aktif')
const alasanTutup = ref('')
const berlaku = ref('')
const dasar = ref('')
const alasan = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  const s = props.sel
  aksi.value = s ? 'ubah' : 'tambah'
  posAsal.value = s?.pos_asal ?? ''
  posTujuan.value = s?.pos_tujuan ?? ''
  izin.value = s?.izin_perintah ?? ''
  keterangan.value = s?.keterangan ?? ''
  status.value = s?.status ?? 'aktif'
  alasanTutup.value = ''
  berlaku.value = hariIni()
  dasar.value = ''
  alasan.value = ''
  kesalahan.value = {}
  error.value = null
})

const judul = computed(() => (props.sel ? `Usulkan perubahan sel ${labelSel(props.sel)}` : 'Usulkan sel matriks baru'))

/** `perubahan.0.nilai_baru.<isian>` → kolom dialog; `perubahan.0.nilai_baru.alasan` = alasan penutupan. */
const PETA_KOLOM: Record<string, string> = { alasan: 'alasan_tutup' }

async function kirim() {
  error.value = null
  const e: Record<string, string> = {}
  const butir = bentukButirMatriks(
    { aksi: aksi.value, pos_asal: posAsal.value, pos_tujuan: posTujuan.value, izin_perintah: izin.value, keterangan: keterangan.value, status: status.value, alasan_tutup: alasanTutup.value },
    props.sel,
  )
  if (!adalahButir(butir)) Object.assign(e, butir)
  if (!berlaku.value) e.berlaku_dari = 'Pilih tanggal berlaku.'
  else if (berlaku.value < hariIni()) e.berlaku_dari = 'Tidak boleh sebelum hari ini.'
  if (dasar.value.trim().length < 3) e.dasar = 'Isi dasar, minimal 3 karakter.'
  if (alasan.value.trim().length < 5) e.alasan = 'Isi alasan usulan, minimal 5 karakter.'
  kesalahan.value = e
  if (e.aksi) error.value = e.aksi
  if (Object.keys(e).length || !adalahButir(butir)) return
  sibuk.value = true
  try {
    const hasil = await buatUsulan({ jenis_data: 'matriks_perpindahan', perubahan: [butir], berlaku_dari: berlaku.value, alasan: alasan.value.trim(), dasar: dasar.value.trim() })
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    if (err instanceof ApiError && err.rincian.length) {
      const kolom: Record<string, string> = {}
      const sisa: string[] = []
      for (const r of err.rincian) {
        const asli = String(r.kolom ?? '')
        const pesan = String(r.pesan ?? r.kode ?? 'Tidak valid.') + (r.usulan && typeof r.usulan === 'object' ? ` (${(r.usulan as { nomor: string }).nomor})` : '')
        const isian = asli.startsWith('perubahan.0.nilai_baru.') ? asli.slice('perubahan.0.nilai_baru.'.length) : null
        const tujuan = isian !== null ? (PETA_KOLOM[isian] ?? isian) : asli
        if (isian !== null && ['pos_asal', 'pos_tujuan', 'izin_perintah', 'keterangan', 'status', 'alasan_tutup'].includes(tujuan)) kolom[tujuan] = pesan
        else if (isian === null && ['berlaku_dari', 'dasar', 'alasan'].includes(tujuan)) kolom[tujuan] = pesan
        else sisa.push(pesan)
      }
      kesalahan.value = kolom
      error.value = sisa.length ? sisa.join(' ') : err.message
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :sibuk="sibuk" :peringatan="aksi === 'tutup'" lebar="lebar">
    <form id="form-usulan-matriks" novalidate @submit.prevent="kirim">
      <p class="ket">Perubahan matriks diverifikasi dan disetujui orang lain, lalu berlaku mulai tanggal yang dipilih. Sel tidak pernah dihapus; sel nonaktif menolak perintah ke tujuan itu.</p>
      <div class="kisi">
        <KolomIsian v-if="sel" v-slot="a" label="Jenis perubahan" wajib>
          <select v-bind="a" v-model="aksi" class="isian">
            <option value="ubah">Ubah izin, keterangan, atau status</option>
            <option v-if="sel.status === 'aktif'" value="tutup">Nonaktifkan sel</option>
          </select>
        </KolomIsian>
        <template v-if="aksi === 'tambah'">
          <KolomIsian v-slot="a" label="Pos asal" wajib :error="kesalahan.pos_asal">
            <select v-bind="a" v-model="posAsal" class="isian">
              <option value="" disabled>Pilih pos asal…</option>
              <option v-for="p in POS_ASAL" :key="p" :value="p">{{ LABEL_POS[p] }}</option>
            </select>
          </KolomIsian>
          <KolomIsian v-slot="a" label="Pos tujuan" wajib :error="kesalahan.pos_tujuan">
            <select v-bind="a" v-model="posTujuan" class="isian">
              <option value="" disabled>Pilih pos tujuan…</option>
              <option v-for="p in POS_TUJUAN" :key="p" :value="p">{{ LABEL_POS[p] }}</option>
            </select>
          </KolomIsian>
        </template>
      </div>

      <template v-if="aksi === 'tutup'">
        <p class="catatan catatan-peringatan">
          <Ikon nama="peringatan" :ukuran="16" />
          <span>Sesudah berlaku, perintah {{ sel ? labelSel(sel) : '' }} ditolak server. Perintah yang sudah tercatat tidak berubah.</span>
        </p>
        <KolomIsian v-slot="a" label="Alasan penutupan" wajib :error="kesalahan.alasan_tutup">
          <textarea v-bind="a" v-model="alasanTutup" class="isian" rows="2" maxlength="500" />
        </KolomIsian>
      </template>
      <div v-else class="kisi">
        <KolomIsian v-slot="a" label="Izin pemberi perintah" wajib petunjuk="Kode izin yang wajib dipegang pemberi perintah, mis. igd.disposisi.input." :error="kesalahan.izin_perintah">
          <input v-bind="a" v-model="izin" class="isian isian-kode" autocomplete="off" maxlength="100" spellcheck="false" />
        </KolomIsian>
        <KolomIsian v-if="aksi === 'ubah'" v-slot="a" label="Status sel" :error="kesalahan.status">
          <select v-bind="a" v-model="status" class="isian">
            <option value="aktif">Aktif</option>
            <option value="nonaktif">Nonaktif</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Keterangan" petunjuk="Sumber aturan, mis. baris PRD 7.00.3. Maksimal 500 karakter." :error="kesalahan.keterangan">
          <textarea v-bind="a" v-model="keterangan" class="isian" rows="2" maxlength="500" />
        </KolomIsian>
      </div>

      <div class="kisi">
        <KolomIsian v-slot="a" label="Berlaku mulai" :error="kesalahan.berlaku_dari" petunjuk="Diterapkan pukul 00.00 waktu RS." wajib>
          <input v-bind="a" v-model="berlaku" type="date" class="isian" :min="hariIni()" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Dasar" :error="kesalahan.dasar" petunjuk="Nomor SK, memo, atau surat." wajib>
          <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" maxlength="200" />
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Alasan usulan" :error="kesalahan.alasan" wajib>
        <textarea v-bind="a" v-model="alasan" class="isian" rows="2" maxlength="500" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-usulan-matriks" :varian="aksi === 'tutup' ? 'bahaya' : 'utama'" :sibuk="sibuk">{{ sibuk ? 'Mengirim…' : 'Ajukan usulan' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.ket {
  margin: 0 0 var(--space-3);
  color: var(--muted);
  font-size: 12.5px;
}
.kisi {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.catatan {
  margin: 0 0 var(--space-3);
}
</style>
