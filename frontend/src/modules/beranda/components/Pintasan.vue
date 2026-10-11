<script setup lang="ts">
import { computed } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import type { Menu } from '@/modules/akses/api'
import { bukaPencarianMenu, labelPintasan } from '@/layouts/pencarianMenu'

/** Pintasan: pencarian menu (Ctrl/Cmd+K) dan halaman yang sudah tersedia dari menu milik akun. */
const props = defineProps<{ modul: Menu[] }>()
const BATAS = 6
const tersedia = computed(() =>
  props.modul
    .flatMap((m) => m.sub_menu.filter((s) => s.status === 'aktif').map((s) => ({ ...s, ikon: m.ikon, induk: m.label })))
    .slice(0, BATAS),
)
const pintasan = labelPintasan()
</script>

<template>
  <section class="kartu" aria-labelledby="judul-pintasan">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-pintasan">Pintasan</h2>
        <p>Disusun dari menu yang Anda miliki.</p>
      </div>
    </div>
    <div class="kartu-isi">
      <button type="button" class="tombol cari" :aria-keyshortcuts="pintasan === '⌘K' ? 'Meta+K' : 'Control+K'" @click.stop="bukaPencarianMenu">
        <Ikon nama="kisi" :ukuran="16" />Cari menu
        <kbd class="kbd" aria-hidden="true">{{ pintasan }}</kbd>
      </button>
      <ul v-if="tersedia.length" class="daftar">
        <li v-for="s in tersedia" :key="s.kode">
          <RouterLink :to="s.rute" class="pintas">
            <Ikon :nama="s.ikon" :ukuran="16" />
            <span>{{ s.label }}<small>{{ s.induk }}</small></span>
          </RouterLink>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.cari {
  width: 100%;
  justify-content: flex-start;
}
.cari .kbd {
  margin-left: auto;
}
.daftar {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
  padding: 0;
  list-style: none;
}
.pintas {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 44px;
  padding: 6px 10px;
  border: 1px solid var(--border);
  border-radius: 10px;
  color: var(--text);
  font-size: 13px;
  text-decoration: none;
}
.pintas:hover {
  background: var(--bg);
}
.pintas:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
.pintas svg {
  flex: none;
  color: var(--brand-600);
}
.pintas small {
  display: block;
  font-size: 11.5px;
  color: var(--muted);
}
</style>
