#!/usr/bin/env bash
# Ukur jeda layanan selama deploy (curl tiap 1 dtk ke nginx CERMAT).
#   scripts/ukur-jeda.sh mulai <log>    # jalan di latar
#   scripts/ukur-jeda.sh selesai <log>  # hentikan & ringkas
# Kode di log: 2xx/3xx = normal, "503p" = halaman "sedang diperbarui", lainnya (000 = koneksi gagal) = error mentah.
set -euo pipefail
cd "$(dirname "$0")/.."
aksi="${1:?mulai|selesai|loop}" log="${2:?berkas log}"

case "$aksi" in
  mulai)
    port="$(scripts/cermat-compose.sh port nginx 80 | head -n1)"
    rm -f "$log" "$log.stop"
    nohup setsid "$0" loop "$log" "http://127.0.0.1:${port##*:}/login" >/dev/null 2>&1 &
    echo "Ukur jeda → http://127.0.0.1:${port##*:}/login, log $log"
    ;;
  loop)
    url="${3:?url}"
    while [ ! -f "$log.stop" ]; do
      hdr="$(curl -s -m 3 -o /dev/null -D - "$url" || true)"
      kode="$(printf '%s\n' "$hdr" | awk 'NR==1{print $2}')"
      kode="${kode:-000}"
      if printf '%s\n' "$hdr" | grep -qi '^x-cermat: *diperbarui'; then kode="${kode}p"; fi
      echo "$(date +%s) $(date +%T) $kode" >> "$log"
      sleep 1
    done
    ;;
  selesai)
    touch "$log.stop"; sleep 2
    [ -s "$log" ] || { echo "Log jeda kosong."; exit 0; }
    grep -Ev ' [23][0-9][0-9]$' "$log" | cut -d' ' -f2- || true
    awk '{ t=$1; k=$3
           if (k ~ /^[23][0-9][0-9]$/) { ok++; if (awal) { d=t-awal; if (d>maks) maks=d; awal=0 } }
           else { if (!awal) awal=t; if (k ~ /p$/) p++; else mentah++ } }
         END { if (awal) { d=t-awal+1; if (d>maks) maks=d }
               printf "Jeda: sampel=%d normal=%d halaman_diperbarui=%d error_mentah=%d jeda_terpanjang=%d dtk\n", NR, ok, p, mentah, maks }' "$log"
    ;;
  *) echo "aksi tidak dikenal: $aksi" >&2; exit 2 ;;
esac
