<template>
  <!-- Satu pesan untuk "tidak ada" dan "tidak diizinkan": klien tidak membedakan keduanya (PRD 9.2.5). -->
  <section class="kartu" aria-labelledby="judul-tidak-ditemukan">
    <h1 id="judul-tidak-ditemukan">Halaman tidak ditemukan</h1>
    <p>Alamat ini tidak ada atau tidak termasuk menu akun Anda.</p>
    <RouterLink to="/" class="tautan">Kembali ke Beranda</RouterLink>
  </section>
</template>

<style scoped>
.kartu {
  max-width: 560px;
  margin: var(--space-8) auto;
  padding: var(--space-8) var(--space-6);
  border: 1px solid var(--border);
  border-radius: var(--radius-card);
  background: var(--surface);
  text-align: center;
}
h1 {
  margin: 0;
  font-size: var(--text-title);
}
p {
  margin: var(--space-2) 0 var(--space-4);
  color: var(--muted);
}
.tautan {
  color: var(--brand-600);
  font-weight: var(--font-weight-semibold);
}
.tautan:focus-visible {
  outline: none;
  border-radius: 4px;
  box-shadow: var(--ring);
}
</style>
