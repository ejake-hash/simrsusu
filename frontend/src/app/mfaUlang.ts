import { ref } from 'vue'

/**
 * Permintaan MFA ulang (tiket 1.5, K11): klien API memanggil mintaMfaUlang() saat menerima 403 mfa_ulang_diperlukan,
 * DialogMfaUlang menjawabnya. Beberapa permintaan yang ditolak bersamaan berbagi satu dialog dan satu jawaban.
 * Sesudah verifikasi berhasil, penolakan yang tiba selama masa verifikasi masih berlaku diulang tanpa dialog kedua
 * ('masa'); masanya dihitung relatif terhadap jam klien agar selisih jam PC dan server tidak berpengaruh.
 */
export type JawabanMfaUlang = 'dialog' | 'masa' | false

export const mfaUlangTerbuka = ref(false)
let janji: Promise<JawabanMfaUlang> | null = null
let selesaikan: ((hasil: JawabanMfaUlang) => void) | null = null
/** Batas masa verifikasi menurut jam klien (ms). */
let berlakuSampai = 0

/** @param paksaDialog true = pengulangan lewat masa tetap ditolak server: lupakan masa itu dan minta kode. */
export function mintaMfaUlang(paksaDialog = false): Promise<JawabanMfaUlang> {
  if (paksaDialog) berlakuSampai = 0
  if (!janji && Date.now() < berlakuSampai) return Promise.resolve('masa')
  if (!janji) {
    janji = new Promise<JawabanMfaUlang>((r) => {
      selesaikan = r
    })
    mfaUlangTerbuka.value = true
  }
  return janji
}

/**
 * true = kode diterima server; `durasiMs` = berlaku_sampai − mfa_terverifikasi_pada dari respons. false = dibatalkan
 * pengguna atau sesi berakhir.
 */
export function jawabMfaUlang(hasil: boolean, durasiMs = 0): void {
  berlakuSampai = hasil && durasiMs > 0 ? Date.now() + durasiMs : 0
  selesaikan?.(hasil ? 'dialog' : false)
  janji = null
  selesaikan = null
  mfaUlangTerbuka.value = false
}

/** Keluar / sesi berakhir: masa verifikasi tidak dibawa ke sesi berikutnya. */
export function lupakanMasaMfaUlang(): void {
  berlakuSampai = 0
}
