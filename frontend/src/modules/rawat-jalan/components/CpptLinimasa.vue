<script setup lang="ts">
import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import KeadaanKosong from '@/design-system/components/KeadaanKosong.vue'
import PenandaTerbatas from '@/design-system/components/PenandaTerbatas.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { linimasaCppt, tulisCppt } from '../api'
import { adaIsi, isiSoap, KUNCI_WORKLIST, LABEL_PROFESI, LABEL_TATANAN, pesanCppt, soapKosong } from '../rawatJalan'
import { cpptTerbatas, type BarisWorklist, type Cppt, type CpptTerbatas, type ProfesiCatatan, type TatananCatatan } from '../tipe'
import EntriCppt from './EntriCppt.vue'
import FormSoap from './FormSoap.vue'

/**
 * Linimasa CPPT pasien (`CpptLinimasa`, 7.26.1 butir 1 dan 5; FR-07-04, FR-07-19): semua PPA menulis di episode yang sama dan
 * membaca lintas kunjungan, terbaru dulu, dengan filter profesi dan pos. Entri berlabel yang tidak boleh dibuka akun ini
 * tampil "Terbatas" tanpa isi; dengan filter, server tidak mengirimnya sama sekali.
 */
const props = defineProps<{ baris: BarisWorklist }>()
const qc = useQueryClient()

const lingkup = ref<'pasien' | 'episode'>('pasien')
const profesi = ref<ProfesiCatatan | ''>('')
const tatanan = ref<TatananCatatan | ''>('')
// shortcut: worklist belum mengirim episode_id, jadi episode diketahui dari entri kunjungan ini; tambahkan ke BarisWorklist bila kontrak memuatnya.
const episodeId = ref<string | null>(null)

const filter = computed(() => ({ profesi: profesi.value || undefined, tatanan: tatanan.value || undefined }))
const sumberId = computed(() => (lingkup.value === 'episode' && episodeId.value ? episodeId.value : props.baris.pasien.id))
const kueri = useInfiniteQuery({
  queryKey: computed(() => [...KUNCI_WORKLIST.cppt, lingkup.value, sumberId.value, filter.value]),
  queryFn: ({ pageParam }) => linimasaCppt(lingkup.value === 'episode' && episodeId.value ? 'episode' : 'pasien', sumberId.value, filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const entri = computed<(Cppt | CpptTerbatas)[]>(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
watch(entri, (daftar) => {
  episodeId.value ??= daftar.find((c): c is Cppt => !cpptTerbatas(c) && c.kunjungan_id === props.baris.kunjungan_id)?.episode_id ?? null
}, { immediate: true })

const sekarang = ref(Date.now())
const jam = setInterval(() => (sekarang.value = Date.now()), 30_000)
onBeforeUnmount(() => clearInterval(jam))

const bisaTulis = computed(() => boleh('rawat_jalan.cppt.input'))
const menulis = ref(false)
const f = reactive(soapKosong())
const tulis = useMutation({
  mutationFn: () => tulisCppt(props.baris.kunjungan_id, isiSoap(f)),
  onSuccess: ({ data }) => {
    tampilkanToast('CPPT disimpan sebagai draf. Sahkan dengan PIN agar tampil bagi petugas lain.', 'ok')
    episodeId.value ??= data.episode_id
    Object.assign(f, soapKosong())
    menulis.value = false
    void qc.invalidateQueries({ queryKey: KUNCI_WORKLIST.cppt })
  },
})

const identitas = computed(() => `${props.baris.pasien.nama} · No. RM ${formatNoRm(props.baris.pasien.no_rm)} · lahir ${formatTanggal(props.baris.pasien.tanggal_lahir)}`)
const adaFilter = computed(() => Boolean(profesi.value || tatanan.value))
</script>

<template>
  <section class="kartu" aria-labelledby="judul-cppt">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-cppt">CPPT</h2>
        <p>{{ identitas }}</p>
      </div>
      <Tombol v-if="bisaTulis && !menulis" varian="utama" @click="menulis = true">Tulis CPPT</Tombol>
    </div>

    <form v-if="menulis" class="tulis" aria-label="Tulis CPPT" @submit.prevent="tulis.mutate()">
      <p class="ket">Kunjungan nomor {{ baris.nomor_tampil }}. Disimpan sebagai draf; sahkan dengan PIN sesudahnya.</p>
      <FormSoap v-model="f" />
      <div role="alert"><Pesan v-if="tulis.isError.value" jenis="error">{{ pesanCppt(tulis.error.value) }}</Pesan></div>
      <div class="aksi">
        <Tombol :disabled="tulis.isPending.value || undefined" @click="menulis = false">Batal</Tombol>
        <Tombol type="submit" varian="utama" :disabled="!adaIsi(f) || undefined" :sibuk="tulis.isPending.value">Simpan draf</Tombol>
      </div>
    </form>

    <div class="baris-filter">
      <KolomIsian v-slot="a" label="Lingkup" :petunjuk="episodeId ? undefined : 'Episode kunjungan ini bisa dipilih setelah ada catatan pada kunjungan ini.'">
        <select v-bind="a" v-model="lingkup" class="isian">
          <option value="pasien">Semua kunjungan pasien</option>
          <option value="episode" :disabled="!episodeId || undefined">Episode kunjungan ini</option>
        </select>
      </KolomIsian>
      <KolomIsian v-slot="a" label="Profesi">
        <select v-bind="a" v-model="profesi" class="isian">
          <option value="">Semua profesi</option>
          <option v-for="(l, k) in LABEL_PROFESI" :key="k" :value="k">{{ l }}</option>
        </select>
      </KolomIsian>
      <KolomIsian v-slot="a" label="Pos">
        <select v-bind="a" v-model="tatanan" class="isian">
          <option value="">Semua pos</option>
          <option v-for="(l, k) in LABEL_TATANAN" :key="k" :value="k">{{ l }}</option>
        </select>
      </KolomIsian>
    </div>

    <div class="isi">
      <p v-if="kueri.isPending.value" class="ket" role="status">Memuat linimasa CPPT…</p>
      <Pesan v-else-if="kueri.isError.value" jenis="error">
        Linimasa tidak dapat dimuat: {{ pesanError(kueri.error.value) }}
        <Tombol varian="tautan" @click="kueri.refetch()">Coba lagi</Tombol>
      </Pesan>
      <KeadaanKosong
        v-else-if="!entri.length"
        :judul="adaFilter ? 'Tidak ada catatan untuk filter ini' : 'Belum ada catatan CPPT'"
        :uraian="adaFilter ? 'Ubah atau kosongkan filter profesi dan pos.' : bisaTulis ? 'Pilih Tulis CPPT untuk mencatat SOAP kunjungan ini.' : 'Catatan muncul setelah petugas yang merawat menulis dan mengesahkannya.'"
      />
      <ol v-else class="linimasa" aria-label="Linimasa CPPT, terbaru dulu">
        <li v-for="c in entri" :key="c.id">
          <div v-if="cpptTerbatas(c)" class="terbatas">
            <time :datetime="c.dibuat_pada" class="ket">{{ formatTanggalWaktu(c.dibuat_pada) }}</time>
            <PenandaTerbatas />
          </div>
          <EntriCppt v-else :entri="c" :sekarang="sekarang" />
        </li>
      </ol>
      <div v-if="kueri.hasNextPage.value" class="aksi">
        <Tombol :sibuk="kueri.isFetchingNextPage.value" @click="kueri.fetchNextPage()">Muat catatan lebih lama</Tombol>
      </div>
    </div>
  </section>
</template>

<style scoped>
.ket {
  margin: 0;
  font-size: 12.5px;
  color: var(--muted);
}
.tulis {
  display: grid;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--border);
}
.baris-filter {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
}
.isi {
  display: grid;
  gap: var(--space-3);
  padding: 0 var(--space-4) var(--space-4);
}
.linimasa {
  display: grid;
  gap: var(--space-3);
  margin: 0;
  padding: 0;
  list-style: none;
}
.terbatas {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border: 1px dashed var(--border-strong);
  border-radius: var(--radius-card);
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
}
</style>
