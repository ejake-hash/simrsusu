<script setup lang="ts">
/**
 * Pesan di atas formulir atau di dalam halaman: error, info, peringatan, berhasil. Ikon dekoratif; makna dari teks
 * (status tidak hanya warna, PRD 5.1 butir 4). Peran ARIA (alert/status) ditentukan pemakai, karena wadah alert
 * yang selalu ada lebih andal dibacakan daripada elemen yang baru muncul. Tanpa margin: jarak diatur pemakai.
 */
withDefaults(defineProps<{ jenis?: 'error' | 'info' | 'peringatan' | 'ok' }>(), { jenis: 'info' })
</script>

<template>
  <div class="pesan" :class="`pesan-${jenis}`">
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
      <template v-if="jenis === 'ok'">
        <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5" />
        <path d="m5 8.2 2 2 4-4.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
      </template>
      <template v-else-if="jenis === 'peringatan'">
        <path d="M8 1.8 1.3 13.5h13.4z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
        <path d="M8 6.5v3.2M8 11.6h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
      </template>
      <template v-else>
        <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5" />
        <path v-if="jenis === 'error'" d="M8 4.5v4M8 11h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
        <path v-else d="M8 7.5v4M8 5h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
      </template>
    </svg>
    <span><slot /></span>
  </div>
</template>

<style scoped>
.pesan {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 10px 12px;
  border-radius: var(--radius-input);
  font-size: 13px;
}
.pesan svg {
  flex: none;
  margin-top: 2px;
}
.pesan-error {
  background: var(--danger-bg);
  color: var(--danger);
}
.pesan-info {
  background: var(--info-bg);
  color: var(--info);
}
.pesan-peringatan {
  background: var(--warn-bg);
  color: var(--warn);
}
.pesan-ok {
  background: var(--success-bg);
  color: var(--success);
}
</style>
