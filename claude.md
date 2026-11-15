# Aturan kerja
- Jangan menjelaskan rencana; langsung kerjakan. Laporan akhir maks 3 baris.
- Cari dengan grep -n terarah, baca hanya baris yang perlu. Jangan buka vendor/, node_modules/, storage/, public/build/.
- Edit dengan perubahan kecil, jangan menulis ulang file, jangan membaca ulang file setelah diedit.
- Test: php artisan test --filter=<nama test terkait> 2>&1 | tail -15
- Bila lulus: git add -A, commit, push origin main. Bila gagal: berhenti dan laporkan.