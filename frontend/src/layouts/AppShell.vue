<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useSesiStore } from '@/app/sesi'

const sesi = useSesiStore()
const router = useRouter()

async function keluar() {
  await sesi.keluar()
  await router.replace({ name: 'masuk' })
}
</script>

<template>
  <main class="shell">
    <header class="kepala">
      <h1>Beranda</h1>
      <p v-if="sesi.pengguna" class="pengguna">
        <span>{{ sesi.pengguna.pegawai?.nama ?? sesi.pengguna.nama_pengguna }}</span>
        <button type="button" class="keluar" @click="keluar">Keluar</button>
      </p>
    </header>
    <RouterView />
  </main>
</template>

<style scoped>
.shell {
  padding: var(--space-6);
}
.kepala {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
}
h1 {
  margin: 0;
  font-size: var(--text-title);
  color: var(--brand-600);
}
.pengguna {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin: 0;
}
.keluar {
  height: 36px;
  padding: 0 var(--space-4);
  border: 1px solid var(--border-input);
  border-radius: var(--radius-input);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-weight: var(--font-weight-semibold);
  cursor: pointer;
}
.keluar:focus-visible {
  outline: none;
  border-color: var(--brand-600);
  box-shadow: var(--ring);
}
</style>
