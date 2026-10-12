// Uji beban endpoint fase 1 (tiket 1.12 butir be 1; PRD 14.1, 14.1.1). Hanya ke lokal/staging dengan akun sintetis,
// BUKAN di CI dan bukan produksi. Cara pakai dan data yang dibutuhkan: README.md.
//
// Skenario `kerja`: VU tetap (VU, bawaan 50), tiap VU memakai sesi akun sintetis sendiri (bergilir dari AKUN akun
// `beban.0001`…) dan memanggil campuran layar fase 1 dengan jeda pikir 0,5–1,5 detik. Polling notifikasi memakai
// ETag dan header latar seperti klien SPA. Skenario `masuk`: masuk → keluar akun lain dengan laju tetap.
import http from 'k6/http'
import { check, fail, sleep } from 'k6'
import exec from 'k6/execution'

const BASE = __ENV.SIMRS_URL || 'http://127.0.0.1:8112'
const ORIGIN = __ENV.SIMRS_ORIGIN || 'http://localhost:8112'
const SANDI = __ENV.K6_SANDI
const VU = Number(__ENV.VU || 50)
const DURASI = __ENV.DURASI || '1m'
const AKUN = Number(__ENV.AKUN || 200)
const AWALAN = __ENV.K6_AWALAN || 'beban.'
// Akun untuk skenario masuk: sesudah akun sesi, agar sesi `kerja` tidak ikut diputus.
const AKUN_MASUK_DARI = AKUN + 1

if (!SANDI) fail('K6_SANDI wajib diisi (sandi akun sintetis).')

const HEADER = { Origin: ORIGIN, Referer: `${ORIGIN}/`, Accept: 'application/json' }

export const options = {
  setupTimeout: '5m',
  scenarios: {
    kerja: { executor: 'constant-vus', exec: 'kerja', vus: VU, duration: DURASI },
    masuk: { executor: 'constant-arrival-rate', exec: 'masuk', rate: Number(__ENV.MASUK_PER_MENIT || 30), timeUnit: '1m', duration: DURASI, preAllocatedVUs: 4 },
  },
  // Anggaran PRD 14.1: baca P95 ≤ 200 ms, simpan P95 ≤ 500 ms (masuk dihitung simpan); error < 0,1% (14.1.1).
  thresholds: {
    'http_req_duration{scenario:kerja}': ['p(95)<200'],
    'http_req_duration{name:masuk}': ['p(95)<500'],
    'http_req_failed{scenario:kerja}': ['rate<0.001'],
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
}

const nama = (i) => `${AWALAN}${String(i).padStart(4, '0')}`

function tokenCsrf(jar) {
  const nilai = jar.cookiesForURL(BASE)['XSRF-TOKEN']
  return nilai ? decodeURIComponent(nilai[0]) : ''
}

function masukSebagai(jar, namaPengguna) {
  http.get(`${BASE}/sanctum/csrf-cookie`, { headers: HEADER, jar, tags: { name: 'csrf-cookie' } })
  const res = http.post(`${BASE}/api/v1/autentikasi/masuk`, JSON.stringify({ nama_pengguna: namaPengguna, sandi: SANDI }), {
    headers: { ...HEADER, 'Content-Type': 'application/json', 'X-XSRF-TOKEN': tokenCsrf(jar) },
    jar,
    tags: { name: 'masuk' },
  })
  return check(res, { 'masuk 200 status=masuk': (r) => r.status === 200 && r.json('status') === 'masuk' }) ? res : null
}

export function setup() {
  const sesi = []
  for (let i = 1; i <= Math.min(AKUN, VU); i++) {
    const jar = http.cookieJar()
    jar.clear(BASE)
    if (!masukSebagai(jar, nama(i))) fail(`Gagal masuk ${nama(i)}: periksa K6_SANDI, SIMRS_ORIGIN, dan jaringan MFA.`)
    sesi.push(jar.cookiesForURL(BASE))
  }
  return { sesi }
}

// Bobot kira-kira layar kerja fase 1: polling notifikasi paling sering, lalu menu dan daftar.
const LAYAR = [
  ['saya-notifikasi', '/api/v1/saya/notifikasi?batas=20', 4],
  ['saya-menu', '/api/v1/saya/menu', 2],
  ['saya', '/api/v1/saya', 1],
  ['daftar-akun', '/api/v1/akun?batas=50', 1],
  ['daftar-pegawai', '/api/v1/pegawai?batas=50', 1],
  ['daftar-lokasi', '/api/v1/lokasi', 1],
  ['daftar-unit', '/api/v1/unit-organisasi', 1],
]
const TOTAL_BOBOT = LAYAR.reduce((n, l) => n + l[2], 0)
const etag = {}

function pilihLayar() {
  let r = Math.random() * TOTAL_BOBOT
  for (const l of LAYAR) {
    r -= l[2]
    if (r < 0) return l
  }
  return LAYAR[0]
}

export function kerja(data) {
  // k6 mengosongkan cookie jar VU di awal iterasi, jadi cookie sesi akun VU ini dipasang ulang.
  const jar = http.cookieJar()
  const kue = data.sesi[(exec.vu.idInTest - 1) % data.sesi.length]
  for (const [n, v] of Object.entries(kue)) jar.set(BASE, n, v[0])
  const [label, jalur] = pilihLayar()
  const header = { ...HEADER }
  if (label === 'saya-notifikasi') {
    header['X-Permintaan-Latar'] = '1'
    if (etag[jalur]) header['If-None-Match'] = etag[jalur]
  }
  const res = http.get(`${BASE}${jalur}`, { headers: header, jar, tags: { name: label } })
  if (res.headers.Etag) etag[jalur] = res.headers.Etag
  check(res, { [`${label} 200/304`]: (r) => r.status === 200 || r.status === 304 })
  if (__ENV.DEBUG && res.status !== 200 && res.status !== 304) console.warn(`${label} ${res.status} ${String(res.body).slice(0, 200)}`)
  sleep(0.5 + Math.random())
}

export function masuk() {
  const jar = http.cookieJar()
  jar.clear(BASE)
  const i = AKUN_MASUK_DARI + (exec.scenario.iterationInTest % 100)
  if (!masukSebagai(jar, nama(i))) return
  const res = http.post(`${BASE}/api/v1/autentikasi/keluar`, null, {
    headers: { ...HEADER, 'X-XSRF-TOKEN': tokenCsrf(jar) },
    jar,
    tags: { name: 'keluar' },
  })
  check(res, { 'keluar 2xx': (r) => r.status >= 200 && r.status < 300 })
}
