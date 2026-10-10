<?php

return [

    // Reverse proxy/load balancer di depan aplikasi: IP atau CIDR, dipisah koma. Hanya dari alamat
    // ini header X-Forwarded-* dipercaya, sehingga $request->ip() (batas laju, audit) berisi IP
    // klien, bukan IP proksi. Kosong = tidak ada proksi. Jangan '*' bila aplikasi bisa dijangkau
    // tanpa lewat proksi: klien bisa memalsukan IP-nya.
    'proksi_tepercaya' => array_values(array_filter(array_map('trim', explode(',', (string) env('PROKSI_TEPERCAYA', ''))))),

];
