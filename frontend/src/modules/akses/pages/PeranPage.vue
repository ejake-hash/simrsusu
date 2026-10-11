<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import Dialog from '@/design-system/components/Dialog.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import type { PeranBerversi } from '../apiPengaturan'
import DaftarPeran from '../components/pengaturan/DaftarPeran.vue'
import DialogInfoPeran from '../components/pengaturan/DialogInfoPeran.vue'
import EditorPeran from '../components/pengaturan/EditorPeran.vue'
import { KUNCI, pesanError, usePohonMenu } from '../pengaturan'

/**
 * Pengaturan → Peran & izin (PRD 9.3, paket 1.2c). Peran terpilih disimpan di ?peran= agar bisa dibagikan
 * dan bertahan saat muat ulang. Berpindah peran atau halaman dengan suntingan belum disimpan → konfirmasi.
 */
const route = useRoute()
const router = useRouter()
const qc = useQueryClient()
const pohon = usePohonMenu()

const terpilih = computed(() => (typeof route.query.peran === 'string' ? route.query.peran : null))
const editor = ref<InstanceType<typeof EditorPeran> | null>(null)

const tujuan = ref<string | null>(null)
const dialogBuang = ref(false)
let izinkanPergi = false

function pilih(id: string | null) {
  if (id === terpilih.value) return
  if (editor.value?.adaPerubahan) {
    tujuan.value = id
    dialogBuang.value = true
    return
  }
  void router.replace({ query: { ...route.query, peran: id ?? undefined } })
}
function buangDanPindah() {
  dialogBuang.value = false
  izinkanPergi = true
  void router.replace({ query: { ...route.query, peran: tujuan.value ?? undefined } }).finally(() => {
    izinkanPergi = false
  })
}

onBeforeRouteLeave(() => {
  if (izinkanPergi || !editor.value?.adaPerubahan) return true
  return window.confirm('Ada perubahan izin yang belum disimpan. Tinggalkan halaman dan buang perubahan?')
})

const dialogBuat = ref(false)
function dibuat(hasil: PeranBerversi) {
  qc.setQueryData(KUNCI.peran(hasil.peran.id), hasil)
  void qc.invalidateQueries({ queryKey: KUNCI.daftarPeran })
  pilih(hasil.peran.id)
}
</script>

<template>
  <HalamanModul
    izin="pengaturan.peran.lihat"
    judul="Peran & izin"
    uraian="Susun izin tiap peran sampai tingkat bagian dan aksi. Setiap perubahan butuh dasar dan tercatat di jejak audit."
    kelas-terbatas
  >
    <div class="tata">
      <DaftarPeran :model-value="terpilih" @update:model-value="pilih" @buat="dialogBuat = true" />

      <div v-if="pohon.isPending.value" class="kartu kartu-isi" role="status">Memuat pohon menu…</div>
      <div v-else-if="pohon.isError.value" class="kartu kartu-isi" role="alert">
        <p class="catatan catatan-bahaya">{{ pesanError(pohon.error.value) }}</p>
        <button type="button" class="tombol" @click="pohon.refetch()">Coba lagi</button>
      </div>
      <EditorPeran v-else-if="terpilih && pohon.indeks.value" :key="terpilih" ref="editor" :peran-id="terpilih" :indeks="pohon.indeks.value" @dibuat="pilih" />
      <section v-else class="kartu kosong">
        <h2>Pilih peran</h2>
        <p>Pilih peran di daftar untuk melihat dan menyunting izinnya, atau buat peran baru.</p>
      </section>
    </div>

    <DialogInfoPeran v-model:open="dialogBuat" mode="buat" :peran="null" :etag="null" @selesai="dibuat" />
    <Dialog v-model:open="dialogBuang" judul="Buang perubahan yang belum disimpan?" uraian="Suntingan matriks peran ini hilang bila Anda berpindah peran." peringatan>
      <template #aksi>
        <button type="button" class="tombol" @click="dialogBuang = false">Kembali menyunting</button>
        <button type="button" class="tombol tombol-bahaya" @click="buangDanPindah">Buang dan pindah</button>
      </template>
    </Dialog>
  </HalamanModul>
</template>

<style scoped>
.tata {
  display: grid;
  grid-template-columns: 300px minmax(0, 1fr);
  align-items: start;
  gap: var(--space-4);
}
.kosong {
  padding: var(--space-8) var(--space-6);
  text-align: center;
  color: var(--muted);
}
.kosong h2 {
  margin: 0 0 var(--space-1);
  font-size: 15px;
  color: var(--text);
}
.kosong p {
  margin: 0;
}

@media (max-width: 1279.98px) {
  .tata {
    grid-template-columns: 260px minmax(0, 1fr);
  }
}
/* Tablet tegak: daftar di atas editor. */
@media (max-width: 1023.98px) {
  .tata {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
