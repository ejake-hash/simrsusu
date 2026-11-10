<script setup lang="ts">
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, nextTick, ref } from 'vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { jamWib } from '@/modules/antrean/antrean'
import { semuaBangsalAktif, semuaKelasRawatAktif } from '@/modules/organisasi/apiLokasi'
import { KUNCI_LOKASI } from '@/modules/organisasi/lokasi'
import { formatTanggal } from '@/modules/pendaftaran/pasien'
import { buatSpri } from '../api'
import { hariIniRs, KUNCI_ADMISI, pesanGalatSpri, tandaPermintaan } from '../admisi'
import type { PermintaanTempatTidur } from '../tipe'

/**
 * SPRI dari Pemeriksaan poli (tiket 2.28, kontrak v0.36.0; 7.00.3 baris Poliklinik → Rawat inap). Induk menampilkannya
 * hanya untuk kunjungan `dilayani` dan pemegang izin sel bawaan (rawat_jalan.pemeriksaan.sahkan); server tetap penentu
 * (matriks, SIP/SPK, cakupan poli), jadi 403/409/422 tampil sebagai pesan. Jam SPRI dan pemberi perintah dari server.
 */
const props = defineProps<{ kunjunganId: string; namaPasien: string }>()
const qc = useQueryClient()

const buka = ref(false)
const kelasId = ref('')
const bangsalId = ref('')
const tanggal = ref(hariIniRs())
const alasan = ref('')
const hasil = ref<PermintaanTempatTidur | null>(null)
const pilihKelas = ref<HTMLSelectElement | null>(null)
const tombolSpri = ref<InstanceType<typeof Tombol> | null>(null)

const kelas = useQuery({ queryKey: KUNCI_LOKASI.kelasAktif, queryFn: semuaKelasRawatAktif, enabled: buka, staleTime: 60_000 })
const bangsal = useQuery({ queryKey: KUNCI_LOKASI.bangsalAktif, queryFn: semuaBangsalAktif, enabled: buka, staleTime: 60_000 })

const kurang = computed(() => {
  if (!kelasId.value) return 'Pilih kelas rawat.'
  if (!tanggal.value || tanggal.value < hariIniRs()) return 'Isi tanggal rencana hari ini atau sesudahnya.'
  if (alasan.value.trim().length < 5) return 'Isi alasan rawat, minimal 5 karakter.'
  return null
})

const simpan = useMutation({
  mutationFn: () =>
    buatSpri(props.kunjunganId, { alasan: alasan.value.trim(), kelas_rawat_id: kelasId.value, bangsal_id: bangsalId.value || null, tanggal_rencana: tanggal.value }),
  onSuccess: async (p) => {
    hasil.value = p
    buka.value = false
    tampilkanToast(`SPRI ${props.namaPasien} tersimpan. Permintaan kamar masuk antrean admisi.`, 'ok')
    void qc.invalidateQueries({ queryKey: KUNCI_ADMISI.semua })
    await nextTick()
    document.getElementById('judul-ringkasan-spri')?.focus()
  },
})
const galatIsian = computed(() => errorKolom(simpan.error.value, ['alasan', 'kelas_rawat_id', 'bangsal_id', 'tanggal_rencana']))
const pesanGalat = computed(() => (simpan.error.value ? pesanGalatSpri(simpan.error.value) : null))

async function mulai() {
  buka.value = true
  simpan.reset()
  await nextTick()
  pilihKelas.value?.focus()
}
async function batal() {
  buka.value = false
  await nextTick()
  const el = tombolSpri.value?.$el as HTMLElement | undefined
  el?.focus()
}
function ajukan() {
  if (kurang.value === null && !simpan.isPending.value) simpan.mutate()
}
</script>

<template>
  <section class="spri" aria-labelledby="judul-spri">
    <h3 id="judul-spri">Rawat inap (SPRI)</h3>

    <div v-if="hasil" class="ringkasan" role="status">
      <h4 id="judul-ringkasan-spri" tabindex="-1">SPRI tersimpan pukul {{ jamWib(hasil.diminta_pada) }}</h4>
      <dl>
        <div><dt>Status</dt><dd><Lencana :jenis="tandaPermintaan(hasil).jenis" tanpa-ikon><Ikon :nama="tandaPermintaan(hasil).ikon" :ukuran="12" />{{ tandaPermintaan(hasil).label }}</Lencana></dd></div>
        <div><dt>Kelas diminta</dt><dd>{{ hasil.kelas_diminta?.nama ?? 'Belum ditentukan' }}</dd></div>
        <div><dt>Bangsal pilihan</dt><dd>{{ hasil.bangsal_pilihan?.nama ?? 'Bebas, admisi memilih' }}</dd></div>
        <div><dt>Rencana rawat</dt><dd>{{ formatTanggal(hasil.spri.tanggal_rencana) }}</dd></div>
        <div><dt>No. SPRI BPJS</dt><dd>{{ hasil.spri.no_surat_bpjs ?? 'Belum ada (bridging BPJS menyusul)' }}</dd></div>
      </dl>
      <p class="tafsir">Admisi mengalokasikan TT dari Pendaftaran → Admisi rawat inap. Jam SPRI menjadi awal rawat inap.</p>
    </div>

    <template v-else>
      <p v-if="!buka" class="tafsir">Pasien perlu dirawat? Buat Surat Perintah Rawat Inap; permintaan kamar masuk antrean admisi.</p>
      <div v-if="!buka"><Tombol ref="tombolSpri" @click="mulai">Buat SPRI</Tombol></div>

      <form v-else class="formulir" novalidate @submit.prevent="ajukan">
        <div class="baris-isian">
          <KolomIsian v-slot="a" label="Kelas rawat diminta" wajib :error="galatIsian.kelas_rawat_id">
            <select v-bind="a" ref="pilihKelas" v-model="kelasId" class="isian" :disabled="kelas.isPending.value || undefined">
              <option value="" disabled>{{ kelas.isPending.value ? 'Memuat kelas…' : 'Pilih kelas' }}</option>
              <option v-for="k in kelas.data.value ?? []" :key="k.id" :value="k.id">{{ k.nama }}</option>
            </select>
          </KolomIsian>
          <KolomIsian v-slot="a" label="Bangsal pilihan (opsional)" petunjuk="Kosong = admisi memilih bangsal." :error="galatIsian.bangsal_id">
            <select v-bind="a" v-model="bangsalId" class="isian">
              <option value="">Bebas</option>
              <option v-for="b in bangsal.data.value ?? []" :key="b.id" :value="b.id">{{ b.nama }}</option>
            </select>
          </KolomIsian>
          <KolomIsian v-slot="a" label="Tanggal rencana rawat" wajib petunjuk="Hari ini atau sesudahnya." :error="galatIsian.tanggal_rencana">
            <input v-bind="a" v-model="tanggal" type="date" class="isian" :min="hariIniRs()" />
          </KolomIsian>
        </div>
        <Pesan v-if="kelas.isError.value" jenis="error">Kelas rawat tidak dapat dimuat: {{ pesanError(kelas.error.value) }}</Pesan>
        <Pesan v-if="bangsal.isError.value" jenis="info">Daftar bangsal tidak dapat dimuat; SPRI tetap bisa disimpan tanpa bangsal pilihan.</Pesan>
        <KolomIsian v-slot="a" label="Alasan rawat" wajib petunjuk="Dasar klinis, 5 sampai 1.000 karakter. Tidak tampil di antrean admisi." :error="galatIsian.alasan">
          <textarea v-bind="a" v-model="alasan" class="isian" rows="3" maxlength="1000"></textarea>
        </KolomIsian>
        <Pesan v-if="pesanGalat" jenis="error">{{ pesanGalat }}</Pesan>
        <div class="aksi">
          <p v-if="kurang" class="tafsir">{{ kurang }}</p>
          <Tombol :disabled="simpan.isPending.value || undefined" @click="batal">Batal</Tombol>
          <Tombol type="submit" varian="utama" :disabled="kurang !== null || undefined" :sibuk="simpan.isPending.value">Simpan SPRI</Tombol>
        </div>
      </form>
    </template>
  </section>
</template>

<style scoped>
.spri {
  display: grid;
  gap: var(--space-2);
}
h3,
h4 {
  margin: 0;
}
h4 {
  font-size: 14px;
}
#judul-ringkasan-spri:focus-visible {
  outline: 2px solid var(--brand-600);
  outline-offset: 2px;
  border-radius: 4px;
}
.tafsir {
  margin: 0;
  font-size: 13px;
  color: var(--muted);
}
.formulir {
  display: grid;
  gap: var(--space-3);
}
.baris-isian {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: var(--space-3);
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: var(--space-2);
}
.ringkasan {
  display: grid;
  gap: var(--space-2);
  padding: var(--space-3);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-input);
}
dl {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--space-2) var(--space-3);
  margin: 0;
}
dt {
  font-size: 12px;
  color: var(--muted);
}
dd {
  margin: 0;
}
</style>
