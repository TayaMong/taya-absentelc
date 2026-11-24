<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function sendJson($code, $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function httpRequest($url, $method = 'GET', $jsonBody = null) {
    $ch = curl_init($url);
    $headers = [
        'Accept: application/json, text/plain, */*',
        'Origin: https://mytelumobile.telkomuniversity.ac.id',
        'Referer: https://mytelumobile.telkomuniversity.ac.id/'
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody);
            $headers[] = 'Content-Type: application/json';
        }
    }

    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    return [
        'ok'   => ($body !== false && $httpCode >= 200 && $httpCode < 300),
        'code' => $httpCode,
        'body' => $body,
        'err'  => $err
    ];
}

function saveToJson($file, $list, $record) {
    $isUpdated = false;
    foreach ($list as $idx => $item) {
        if (isset($item['nim']) && $item['nim'] === $record['nim']) {
            $list[$idx] = $record;
            $isUpdated = true;
            break;
        }
    }

    if (!$isUpdated) {
        $list[] = $record;
    }

    $written = file_put_contents($file, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($written === false) {
        sendJson(500, ['status' => 'error', 'message' => 'Gagal menulis ke file data_ktm.json.']);
    }

    return $isUpdated;
}

$jsonFile = __DIR__ . '/data_ktm.json';
$existingData = [];

if (file_exists($jsonFile)) {
    $decoded = json_decode(file_get_contents($jsonFile), true);
    if (is_array($decoded)) {
        $existingData = $decoded;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    sendJson(200, $existingData);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $rawValue = trim($input['ktm_url'] ?? '');
    if ($rawValue === '') {
        sendJson(400, ['status' => 'error', 'message' => 'Masukkan link KTM atau NIM terlebih dahulu.']);
    }

    $nim = '';
    $ktmUrl = '';
    $decryptedUserData = [];

    // 1. Jika input berupa NIM langsung atau link show-ktm
    if (preg_match('/^\d{8,15}$/', $rawValue)) {
        $nim = $rawValue;
    } elseif (preg_match('#/show-ktm/([^/?\#]+)#', $rawValue, $matches)) {
        $ktmUrl = $rawValue;
        $encryptedCode = $matches[1];

        $candidateKeys = ['code', 'qrcode', 'encrypted', 'data', 'text'];
        $decryptRes = null;

        foreach ($candidateKeys as $key) {
            $decryptRes = httpRequest(
                'https://mobile.telkomuniversity.ac.id/v2/qrcodes/decrypt-ktm-code-admin',
                'POST',
                [$key => $encryptedCode]
            );

            if ($decryptRes['ok']) {
                break;
            }
        }

        if (!$decryptRes || !$decryptRes['ok']) {
            sendJson(502, [
                'status'       => 'error',
                'message'      => 'Gagal dekripsi link KTM (HTTP ' . ($decryptRes['code'] ?? 0) . ').',
                'api_response' => $decryptRes['body'] ?? ''
            ]);
        }

        $decryptJson = json_decode($decryptRes['body'], true);
        if (is_array($decryptJson['data'] ?? null)) {
            $decryptedUserData = $decryptJson['data'];
            $nim = trim($decryptedUserData['number_id'] ?? $decryptedUserData['numberid'] ?? $decryptedUserData['nim'] ?? '');
        } elseif (is_string($decryptJson['data'] ?? null)) {
            $nim = trim($decryptJson['data']);
        }

        if ($nim === '') {
            sendJson(422, [
                'status'       => 'error',
                'message'      => 'NIM tidak ditemukan pada hasil dekripsi.',
                'api_response' => $decryptJson
            ]);
        }
    } else {
        sendJson(400, ['status' => 'error', 'message' => 'Format input tidak dikenali. Gunakan link show-ktm atau NIM.']);
    }

    // 2. Ambil juga data dari endpoint v2/users/ktm/{NIM} lalu gabungkan dengan hasil dekripsi
    $profileData = [];
    $ktmRes = httpRequest("https://mobile.telkomuniversity.ac.id/v2/users/ktm/{$nim}", 'GET');
    if ($ktmRes['ok']) {
        $ktmJson = json_decode($ktmRes['body'], true);
        if (is_array($ktmJson['data'] ?? null)) {
            $profileData = $ktmJson['data'];
        }
    }

    // Gabungkan kedua sumber data (hasil dekripsi memiliki field lengkap seperti class, class_of, faculty, study_program)
    $userData = array_merge($profileData, $decryptedUserData);

    $finalNim = trim($userData['number_id'] ?? $userData['numberid'] ?? $nim);
    $finalNama = trim($userData['name'] ?? $userData['fullname'] ?? '');

    if ($finalNim === '' || $finalNama === '') {
        sendJson(404, [
            'status'       => 'error',
            'message'      => 'Data KTM tidak ditemukan untuk NIM ' . $nim,
            'api_response' => $userData
        ]);
    }

    $record = [
        'nim'        => $finalNim,
        'username'   => trim($userData['username'] ?? ''),
        'nama'       => $finalNama,
        'photo'      => trim($userData['photo'] ?? ''),
        'role'       => strtoupper(trim($userData['status'] ?? $userData['role'] ?? 'MAHASISWA')),
        'fakultas'   => trim($userData['faculty'] ?? ''),
        'prodi'      => trim($userData['study_program'] ?? ''),
        'kelas'      => trim($userData['class'] ?? ''),
        'angkatan'   => trim($userData['class_of'] ?? ''),
        'ktm_url'    => $ktmUrl,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $isUpdated = saveToJson($jsonFile, $existingData, $record);

    sendJson(200, [
        'status'  => 'success',
        'message' => $isUpdated ? 'Data KTM berhasil diperbarui di data_ktm.json.' : 'Data KTM berhasil diambil dan disimpan ke data_ktm.json.',
        'data'    => $record
    ]);
}