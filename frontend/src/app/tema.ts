/**
 * Tema dari paket identitas RS (PRD 3.4, 5.3): dua warna dasar dari GET /profil-rs (`brand`, `accent`) menjadi token
 * `--brand-*`, `--ring`, `--accent*` di :root. Turunan dihitung di sini (kontrak: "turunan warna dihitung frontend")
 * dengan jaminan kontras pasangan yang dipakai layar: teks --brand-800 di --brand-25/50 dan teks --accent-ink di
 * --accent-bg/putih ≥ 4,5:1. Panel merek halaman masuk (--panel-merek*) juga diturunkan dari warna utama: teks putih dan
 * teks lembut (--brand-100) ≥ 4,5:1 di setiap titik gradien termasuk sorotnya, dan --penekanan-panel (dari aksen) ≥ 4,5:1
 * di semua titik itu. Warna yang sama dengan tokens.css tidak ditimpa (tampilan bawaan tetap persis).
 * Status, triase, dan netral milik produk dan tidak pernah ditimpa paket RS.
 */
export type Rgb = [number, number, number]
const PUTIH: Rgb = [255, 255, 255]
const HITAM: Rgb = [0, 0, 0]

export function hexKeRgb(hex: string): Rgb | null {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim())
  if (!m) return null
  const n = Number.parseInt(m[1]!, 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}
export function rgbKeHex([r, g, b]: Rgb): string {
  return `#${[r, g, b].map((x) => Math.round(Math.min(255, Math.max(0, x))).toString(16).padStart(2, '0')).join('').toUpperCase()}`
}
/** Campuran sRGB: t = porsi warna kedua (0–1). */
export function campur(a: Rgb, b: Rgb, t: number): Rgb {
  return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t]
}
function luminans([r, g, b]: Rgb): number {
  const k = (c: number) => {
    const s = c / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  }
  return 0.2126 * k(r) + 0.7152 * k(g) + 0.0722 * k(b)
}
/** Rasio kontras WCAG 2.x. */
export function kontras(a: Rgb, b: Rgb): number {
  const [l1, l2] = [luminans(a), luminans(b)].sort((x, y) => y - x) as [number, number]
  return (l1 + 0.05) / (l2 + 0.05)
}
/** Warna seperti yang benar-benar dipasang (HEX dibulatkan): kontras dihitung dari nilai ini, bukan pecahan. */
const bulat = (w: Rgb): Rgb => hexKeRgb(rgbKeHex(w))!
/** Langkah 0, 5 %, …, 100 % (penghitung bulat: langkah terakhir benar-benar 100 %). */
const LANGKAH = Array.from({ length: 21 }, (_, i) => i / 20)

/** Gelapkan sampai kontras dengan semua latar ≥ minimal; hitam selalu menjadi langkah terakhir. */
function cukupGelap(awal: Rgb, latar: Rgb[], minimal = 4.5): Rgb {
  for (const t of LANGKAH) {
    const w = bulat(campur(awal, HITAM, t))
    if (latar.every((l) => kontras(w, bulat(l)) >= minimal)) return w
  }
  return HITAM
}
/** Terangkan sampai kontras dengan semua latar ≥ minimal; putih selalu menjadi langkah terakhir. */
function cukupTerang(awal: Rgb, latar: Rgb[], minimal = 4.5): Rgb {
  for (const t of LANGKAH) {
    const w = bulat(campur(awal, PUTIH, t))
    if (latar.every((l) => kontras(w, l) >= minimal && kontras(w, bulat(l)) >= minimal)) return w
  }
  return PUTIH
}

/** Porsi sorot (radial --panel-merek-sorot 45 %) di atas gradien panel, sama dengan AuthLayout. */
export const PORSI_SOROT = 0.45

/**
 * Latar panel merek yang harus lolos: tiga titik gradien dan sorot 45 % di atas tiap titik. Luminans campuran sRGB
 * cembung sepanjang garis campuran, jadi warna paling terang di seluruh gradien (dan di bawah sorot 0–45 %) ada di
 * titik-titik ujung ini: teks terang yang lolos di sini lolos di seluruh panel.
 */
export function latarPanel(t: Record<string, string>): Rgb[] {
  const titik = ['--panel-merek', '--panel-merek-tengah', '--panel-merek-akhir'].map((k) => hexKeRgb(t[k]!)!)
  const sorot = hexKeRgb(t['--panel-merek-sorot']!)!
  // Campuran sorot diuji dua kali: dibulatkan dan pecahan (peramban mengomposit dengan float).
  return [...titik, ...titik.flatMap((x) => [bulat(campur(x, sorot, PORSI_SOROT)), campur(x, sorot, PORSI_SOROT)])]
}

/**
 * Panel merek halaman masuk dari warna utama dan aksen (catatan tinjauan #78): titik gradien digelapkan sampai teks
 * putih dan --brand-100 ≥ 4,5:1; sorot digelapkan sampai campurannya di atas ketiga titik juga lolos; penekanan dari
 * aksen diterangkan sampai ≥ 4,5:1 di semua latar itu (putih selalu lolos, jadi selalu ada hasil).
 */
function panelMerek(brand: Rgb, accent: Rgb, b100: Rgb): Record<string, string> {
  const teks = [PUTIH, bulat(b100)]
  const titik = [campur(brand, HITAM, 0.4), campur(brand, HITAM, 0.3), campur(brand, HITAM, 0.45)].map((w) => cukupGelap(w, teks))
  const sorotAwal = campur(brand, PUTIH, 0.18)
  let sorot = HITAM
  for (const t of LANGKAH) {
    const s = bulat(campur(sorotAwal, HITAM, t))
    const campuran = titik.flatMap((x) => [bulat(campur(x, s, PORSI_SOROT)), campur(x, s, PORSI_SOROT)])
    if (campuran.every((x) => teks.every((y) => kontras(x, y) >= 4.5))) {
      sorot = s
      break
    }
  }
  const hasil = {
    '--panel-merek': rgbKeHex(titik[0]!),
    '--panel-merek-tengah': rgbKeHex(titik[1]!),
    '--panel-merek-akhir': rgbKeHex(titik[2]!),
    '--panel-merek-sorot': rgbKeHex(sorot),
  }
  return { ...hasil, '--penekanan-panel': rgbKeHex(cukupTerang(campur(accent, PUTIH, 0.55), latarPanel(hasil))) }
}

export function turunanTema(brandHex: string, accentHex: string): Record<string, string> | null {
  const brand = hexKeRgb(brandHex)
  const accent = hexKeRgb(accentHex)
  if (!brand || !accent) return null
  const b25 = campur(brand, PUTIH, 0.95)
  const b50 = campur(brand, PUTIH, 0.9)
  const b100 = campur(brand, PUTIH, 0.8)
  const accentBg = campur(accent, PUTIH, 0.85)
  return {
    '--brand-900': rgbKeHex(cukupGelap(campur(brand, HITAM, 0.45), [b25, b50], 7)),
    '--brand-800': rgbKeHex(cukupGelap(campur(brand, HITAM, 0.3), [b25, b50])),
    '--brand-700': rgbKeHex(campur(brand, HITAM, 0.15)),
    '--brand-600': rgbKeHex(brand),
    '--brand-500': rgbKeHex(campur(brand, PUTIH, 0.18)),
    '--brand-100': rgbKeHex(b100),
    '--brand-50': rgbKeHex(b50),
    '--brand-25': rgbKeHex(b25),
    '--ring': `0 0 0 3px rgba(${brand.map(Math.round).join(', ')}, .25)`,
    '--accent': rgbKeHex(accent),
    '--accent-bg': rgbKeHex(accentBg),
    '--accent-ink': rgbKeHex(cukupGelap(campur(accent, HITAM, 0.5), [accentBg, PUTIH])),
    ...panelMerek(brand, accent, b100),
  }
}

const DITIMPA = [
  '--brand-900', '--brand-800', '--brand-700', '--brand-600', '--brand-500', '--brand-100', '--brand-50', '--brand-25', '--ring', '--accent', '--accent-bg',
  '--accent-ink', '--panel-merek', '--panel-merek-tengah', '--panel-merek-akhir', '--panel-merek-sorot', '--penekanan-panel',
]

/** Pasang (atau lepas, bila sama dengan tokens.css / tidak ada) tema RS di :root. */
export function terapkanTema(warna: { brand: string; accent: string } | null, akar: HTMLElement = document.documentElement): void {
  DITIMPA.forEach((t) => akar.style.removeProperty(t))
  if (!warna) return
  const gaya = getComputedStyle(akar)
  const samaBawaan = (token: string, hex: string) => gaya.getPropertyValue(token).trim().toUpperCase() === hex.trim().toUpperCase()
  if (samaBawaan('--brand-600', warna.brand) && samaBawaan('--accent', warna.accent)) return
  const turunan = turunanTema(warna.brand, warna.accent)
  if (!turunan) return
  for (const [t, v] of Object.entries(turunan)) akar.style.setProperty(t, v)
}
