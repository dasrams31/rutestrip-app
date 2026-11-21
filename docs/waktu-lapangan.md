# Waktu naik: Naismith dan perkiraan lapangan

RuteStrip menghitung waktu naik dari GPX dengan aturan Naismith:
`jam = jarak_km / 5 + total_naik_m / 600`, dari basecamp sampai puncak.
Rumus ini tidak memasukkan istirahat, beban carrier, atau medan berpasir dan berbatu,
jadi hasilnya lebih cepat dari waktu yang biasa ditempuh pendaki.

Halaman jalur menampilkan angka Naismith apa adanya (angka ini juga yang dipakai
untuk pengurutan dan evaluasi), lalu menambahkan perkiraan lapangan berupa rentang:

    perkiraan lapangan = Naismith x 1,4  sampai  Naismith x 1,8
    (dibulatkan ke setengah jam: bawah dibulatkan ke bawah, atas ke atas)

Konstanta ada di `App\Models\HikingRoute::FIELD_FACTOR_LOW` dan `FIELD_FACTOR_HIGH`.

## Dari mana angka 1,4 dan 1,8

Waktu tempuh basecamp sampai puncak yang umum disebut (media, pengelola, dan
catatan pendaki, pencarian 21 November 2026) dibandingkan dengan waktu Naismith
dari GPX RuteStrip setelah jalurnya dipotong menjadi bagian naik saja.

| Jalur | Naismith | Waktu umum | Rasio bawah | Rasio atas |
|---|---|---|---|---|
| Merbabu via Selo | 3,39 j | 6–8 j | 1,77 | 2,36 |
| Lawu via Cemoro Sewu | 3,48 j | 6–8 j | 1,72 | 2,30 |
| Sindoro via Kledung | 4,43 j | 6–8 j | 1,35 | 1,81 |
| Semeru (Ranu Pani) | 7,64 j | 10–12 j jalan, tanpa istirahat | 1,31 | 1,57 |
| Prau via Wates | 2,40 j | 3–4 j | 1,25 | 1,67 |
| Penanggungan via Tamiajeng | 2,39 j | 3–4 j | 1,26 | 1,67 |
| Gede via Gunung Putri (Sukatani) | 3,54 j | 5–7 j | 1,41 | 1,98 |

Median rasio bawah 1,35 dan median rasio atas 1,81, dibulatkan menjadi 1,4 dan 1,8.
Gunung besar dengan carrier berat (Merbabu, Lawu) cenderung di atas rentang ini,
jalur pendek dan landai (Prau, Penanggungan) di dalamnya.

Sumber waktu tempuh:
- Merbabu via Selo: [Visit Jawa Tengah](https://visitjawatengah.jatengprov.go.id/id/artikel/hiking-gunung-merbabu-keindahan-luar-biasa-dengan-jalur-pendakian-mantap-jiwa), [CNN Indonesia](https://www.cnnindonesia.com/gaya-hidup/20230607154009-275-958844/5-jalur-pendakian-gunung-merbabu-via-selo-hingga-suwanting), [Merapi Adventure](https://www.merapiadventure.com/news358-jalur-pendakian-gunung-merbabu-via-basecamp-selo.html)
- Lawu via Cemoro Sewu: [Kumparan](https://kumparan.com/jendela-dunia/naik-gunung-lawu-berapa-jam-ini-dia-estimasi-waktunya-dari-berbagai-jalur-23DSJ4sUJcg), [Tugu Jatim](https://tugujatim.id/pendakian-gunung-lawu-via-cemoro-sewu/)
- Sindoro via Kledung: [IDN Times](https://www.idntimes.com/travel/destination/pendakian-gunung-sindoro-via-kledung-c1c2-01-6n9g9-6jm0xz), [Sobathiking](https://sobathiking.com/gunung-sindoro-jalur-pendakian/)
- Semeru: [Traveloka](https://www.traveloka.com/id-id/explore/destination/pl-jalur-pendakian-gunung-semeru/273965), [muncak.id](https://muncak.id/en/hiking-trail/gunung-semeru-via-ranu-pani)
- Prau via Wates: [IDN Times](https://www.idntimes.com/travel/destination/berapa-lama-naik-gunung-prau-via-wates-ini-estimasi-waktunya-c1c2-01-6n9g9-g50dq7)
- Penanggungan via Tamiajeng: [IDN Times](https://www.idntimes.com/travel/destination/berapa-lama-naik-gunung-penanggungan-ini-estimasi-waktunya-c1c2-01-6n9g9-kcb51g), [Tribun Travel](https://travel.tribunnews.com/2025/03/07/itinerary-pendakian-tektok-gunung-penanggungan-via-tamiajeng-disebut-miniaturnya-gunung-semeru)
- Gede via Gunung Putri: [IDN Times](https://www.idntimes.com/travel/tips/berapa-lama-mendaki-gunung-gede-pangrango-c1c2-01-6n9g9-6x71yx)

Catatan: rentang ini perkiraan kasar dari tujuh jalur, bukan hasil pengukuran sendiri.
Waktu sebenarnya tergantung kondisi fisik, beban, cuaca, dan lama istirahat.
