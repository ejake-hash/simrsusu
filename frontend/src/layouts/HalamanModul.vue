<script setup lang="ts">
import { computed, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import { cariPosisi, useMenuSaya } from '@/app/menu'
import NavigasiSubMenu from '@/design-system/components/NavigasiSubMenu.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { useKelasLayar } from '@/shared/composables/useKelasLayar'
import TidakDitemukanPage from '@/shared/pages/TidakDitemukanPage.vue'

/**
 * Kerangka halaman sub-menu modul: gerbang izin, judul, dan navigasi sub-menu saudara (wajib, karena sidebar
 * 64 px tidak menampilkan sub-menu). Tanpa izin `izin` → Halaman tidak ditemukan (tidak dibedakan dari tidak
 * ada, sama dengan HalamanMenu). Isi slot baru dipasang setelah izin pasti, jadi permintaan datanya tidak
 * pernah dikirim untuk akun yang tidak berhak.
 * `kelasTerbatas` = layar kerja yang tidak didukung di ponsel (PRD 5.5 kelas terbatas): tampil pesan saja.
 */
const props = withDefaults(defineProps<{ izin: string; judul: string; uraian?: string; kelasTerbatas?: boolean }>(), {
  uraian: undefined,
  kelasTerbatas: false,
})

const route = useRoute()
const menu = useMenuSaya()
const kelas = useKelasLayar()

const diizinkan = computed(() => menu.data.value?.izin.includes(props.izin) ?? false)
const saudara = computed(() => {
  if (!menu.data.value) return []
  return cariPosisi(menu.data.value.grup, route.path)?.menu.sub_menu ?? []
})
const induk = computed(() => (menu.data.value ? (cariPosisi(menu.data.value.grup, route.path)?.menu.label ?? null) : null))

watchEffect(() => {
  if (menu.data.value) document.title = `${diizinkan.value ? props.judul : 'Halaman tidak ditemukan'} · Karsitara`
})
</script>

<template>
  <template v-if="menu.data.value">
    <TidakDitemukanPage v-if="!diizinkan" />
    <section v-else-if="kelasTerbatas && kelas === 'ponsel'" class="terbatas" aria-labelledby="judul-terbatas">
      <Ikon nama="info" :ukuran="24" />
      <h1 id="judul-terbatas">{{ judul }}</h1>
      <p>Halaman ini dibuka dari tablet atau komputer. Di ponsel, pengaturan peran dan akun tidak tersedia agar perubahan akses tidak dibuat dari layar sempit.</p>
      <RouterLink to="/" class="tombol">Kembali ke Beranda</RouterLink>
    </section>
    <template v-else>
      <div class="kepala-halaman">
        <div class="judul">
          <h1>{{ judul }}</h1>
          <p v-if="uraian">{{ uraian }}</p>
        </div>
        <NavigasiSubMenu v-if="saudara.length > 1" :daftar="saudara" :label="`Sub-menu ${induk ?? ''}`.trim()" />
      </div>
      <slot />
    </template>
  </template>
</template>

<style scoped>
.kepala-halaman {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--space-3) var(--space-6);
  margin-bottom: 18px;
}
.judul {
  min-width: 0;
  max-width: 760px;
}
h1 {
  margin: 0 0 var(--space-1);
  font-size: var(--text-title);
  line-height: 1.2;
  letter-spacing: -0.02em;
}
.judul p {
  margin: 0;
  color: var(--muted);
}
.terbatas {
  max-width: 480px;
  margin: var(--space-6) auto;
  padding: var(--space-6);
  border: 1px solid var(--border);
  border-radius: var(--radius-card);
  background: var(--surface);
  text-align: center;
  color: var(--muted);
}
.terbatas svg {
  color: var(--info);
}
.terbatas h1 {
  margin: var(--space-2) 0;
  color: var(--text);
  font-size: 18px;
}
.terbatas p {
  margin: 0 0 var(--space-4);
}
</style>
