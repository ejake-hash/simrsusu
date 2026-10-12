<script setup lang="ts">
import { computed, ref } from 'vue'
import Tabs from '@/design-system/components/Tabs.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import DialogRiwayatTugas from '@/modules/notifikasi/components/DialogRiwayatTugas.vue'
import { daftarTugas } from '@/modules/notifikasi/api'
import { KUNCI_NOTIF, LABEL_TINGKAT, PIL_TINGKAT, TAB_TUGAS, keteranganTenggat } from '@/modules/notifikasi/notifikasi'
import { useKueriBerPolling } from '@/modules/notifikasi/polling'
import type { HalamanTugas, TabTugas, Tugas } from '@/modules/notifikasi/tipe'
import { formatTanggalWaktu } from '@/shared/utils/waktu'

/**
 * Kotak kerja (PRD 7.53, tiket 1.7 fe 2): tab Tugas saya / Sudah dieskalasi / Unit saya / Selesai bulan ini (saling
 * lepas, jumlah per tab dari server). Tab aktif diperbarui lewat polling 30 detik (ETag, permintaan latar). Tugas
 * diselesaikan di layarnya lewat tautan (bukan tombol selesai di sini); riwayat tugas di dialog. Ponsel: ringkas.
 */
const tab = ref<TabTugas>('saya')
const BATAS = 20
const { kueri } = useKueriBerPolling<HalamanTugas>(
  () => KUNCI_NOTIF.tabTugas(tab.value),
  (p) => daftarTugas(tab.value, null, BATAS, p),
)
const jumlah = computed(() => kueri.data.value?.meta.jumlah)
const daftarTab = computed(() => TAB_TUGAS.map((t) => ({ nilai: t.nilai, label: jumlah.value ? `${t.label} (${jumlah.value[t.nilai]})` : t.label })))
const tugas = computed(() => kueri.data.value?.data ?? [])
const sisa = computed(() => (jumlah.value ? jumlah.value[tab.value] - tugas.value.length : 0))

const dialog = ref(false)
const dipilih = ref<string | null>(null)
function riwayat(t: Tugas) {
  dipilih.value = t.id
  dialog.value = true
}
</script>

<template>
  <section class="kartu" aria-labelledby="judul-kotak-kerja">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-kotak-kerja">Kotak kerja</h2>
        <p>Tugas yang tertinggal dikejar sistem: pengingat, teguran, lalu eskalasi.</p>
      </div>
    </div>
    <div class="kartu-isi">
      <Tabs v-model="tab" label="Kotak kerja" :daftar="daftarTab">
        <template v-for="t in TAB_TUGAS" :key="t.nilai" #[t.nilai]>
          <p v-if="kueri.isPending.value" class="keadaan" role="status">Memuat tugas…</p>
          <div v-else-if="kueri.isError.value" class="keadaan" role="alert">
            <p>{{ pesanError(kueri.error.value) }}</p>
            <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
          </div>
          <p v-else-if="!tugas.length" class="keadaan">{{ t.kosong }}</p>
          <ul v-else class="daftar-tugas" :aria-label="t.label">
            <li v-for="x in tugas" :key="x.id" class="tugas" :class="{ terlambat: x.terlambat && x.status === 'terbuka' }">
              <div class="utama">
                <RouterLink v-if="x.status === 'terbuka'" :to="x.tautan" class="judul">{{ x.judul }}</RouterLink>
                <span v-else class="judul">{{ x.judul }}</span>
                <span class="ket">
                  {{ x.jenis.nama }} · {{ x.objek.label }}<template v-if="tab !== 'saya' && tab !== 'dieskalasi'"> · {{ x.pelaksana.nama }}</template>
                </span>
              </div>
              <div class="status">
                <span v-if="x.kritis" class="pil pil-bahaya">Kritis</span>
                <span v-if="x.status === 'terbuka' && x.tingkat_saat_ini > 0" class="pil" :class="PIL_TINGKAT[x.tingkat_saat_ini]">{{ LABEL_TINGKAT[x.tingkat_saat_ini] }}</span>
                <span class="tenggat" :class="{ lewat: x.terlambat }" :title="formatTanggalWaktu(x.tenggat_pada) ?? undefined">{{ keteranganTenggat(x) }}</span>
                <button type="button" class="tombol tombol-tautan" :aria-label="`Riwayat: ${x.judul}`" @click="riwayat(x)">Riwayat</button>
              </div>
            </li>
          </ul>
          <p v-if="sisa > 0" class="keadaan kecil">Menampilkan {{ tugas.length }} dari {{ jumlah?.[tab] }} tugas; yang tenggatnya terdekat lebih dulu.</p>
        </template>
      </Tabs>
    </div>
    <DialogRiwayatTugas v-model:open="dialog" :tugas-id="dipilih" />
  </section>
</template>

<style scoped>
.keadaan {
  margin: 0;
  padding: var(--space-6) 0;
  text-align: center;
  color: var(--muted);
}
.keadaan.kecil {
  padding: var(--space-2) 0 0;
  font-size: 12.5px;
}
.daftar-tugas {
  display: grid;
  margin: 0;
  padding: 0;
  list-style: none;
}
.tugas {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2) var(--space-3);
  padding: var(--space-3) 0;
  border-bottom: 1px solid var(--border);
}
.tugas.terlambat {
  box-shadow: inset 3px 0 0 var(--danger);
  padding-left: var(--space-3);
}
.utama {
  display: grid;
  gap: 2px;
  min-width: 0;
  flex: 1 1 260px;
}
.judul {
  color: var(--text);
  font-weight: var(--font-weight-semibold);
}
a.judul {
  color: var(--brand-700);
}
.ket {
  color: var(--muted);
  font-size: 12.5px;
}
.status {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
.tenggat {
  color: var(--muted);
  font-size: 12.5px;
  white-space: nowrap;
}
.tenggat.lewat {
  color: var(--danger);
  font-weight: var(--font-weight-semibold);
}
@media (max-width: 767.98px) {
  .status .pil-info {
    display: none;
  }
}
</style>
