<?php
// 1. Obtener el parámetro 'pid' directamente de la query actual
if (!isset($_GET['pid'])) {
    die("Falta el parámetro 'pid' en la URL.");
}

$pidRaw = $_GET['pid']; // Ej: /project/morboseo-56djrd?tab=uiBuilder&page=Homes

// 2. Limpiar y extraer el valor entre "/project/" y "?" (si hay)
$matches = [];
if (preg_match('#/project/([^/?]+)#', $pidRaw, $matches)) {
    $pid = $matches[1]; // Este es el valor que buscamos
} else {
    die("No se pudo extraer el PID desde el parámetro.");
}

echo "PID extraído: $pid\n";

// 3. Preparar la llamada a la API
$apiUrl = "https://api.flutterflow.io/v1/exportCode";
$token = "c86c0876-c47e-494e-8784-876780c6b516"; // <-- Reemplazalo con tu token real

$postData = [
    "project" => [ "path" => "projects/" . $pid ],
    "export_as_module" => false,
    "include_assets_map" => true,
    "format" => true,
    "export_as_debug" => false
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
$response = curl_exec($ch);

if (curl_errno($ch)) {
    die("Error en la solicitud CURL: " . curl_error($ch));
}
curl_close($ch);

// 4. Guardar la respuesta en /projects/PID.json
$dir = __DIR__ . '/projects';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

file_put_contents($dir . '/' . $pid . '.json', $response);

echo "Guardado correctamente en projects/$pid.json\n";


// 5. Decodificar y guardar el ZIP (firebase_zip)
$responseData = json_decode($response, true);

if (!isset($responseData['value']['project_zip'])) {
    die("No se encontró 'project_zip' en la respuesta.");
}

// zip Project Code
$zipBase64 = $responseData['value']['project_zip'];
$zipBinary = base64_decode($zipBase64);

if ($zipBinary === false) {
    die("Fallo al decodificar el ZIP en base64.");
}

// 6. Guardar el ZIP
$zipPath = $dir . '/' . $pid . '.zip';
file_put_contents($zipPath, $zipBinary);
//-----------------

echo "ZIP guardado correctamente en: $zipPath\n";

// Asegurate de que $pid y $responseData ya estén definidos

$assets = $responseData['value']['assets'] ?? [];

if (empty($assets)) {
    die("No se encontraron assets para descargar.");
}

$tmpDir = sys_get_temp_dir() . "/assets_$pid";
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0777, true);
}

// Descargar cada asset y guardarlo con su ruta relativa
foreach ($assets as $asset) {
    $assetPath = $asset['path'];
    $assetUrl = $asset['url'];

    $fullPath = $tmpDir . '/' . $assetPath;
    $dirPath = dirname($fullPath);

    if (!is_dir($dirPath)) {
        mkdir($dirPath, 0777, true);
    }

    $assetContent = file_get_contents($assetUrl);
    if ($assetContent === false) {
        echo "Error al descargar: $assetUrl\n";
        continue;
    }

    file_put_contents($fullPath, $assetContent);
}

// Crear el ZIP
$zipPath = __DIR__ . "/projects/$pid.assets.zip";
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("No se pudo crear el archivo ZIP.");
}

// Agregar archivos al ZIP manteniendo estructura
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    $localName = substr($file, strlen($tmpDir) + 1); // ruta relativa dentro del zip
    $zip->addFile($file, $localName);
}

$zip->close();

// Limpieza opcional del directorio temporal
function deleteDir($dir) {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item == '.' || $item == '..') continue;
        $path = "$dir/$item";
        is_dir($path) ? deleteDir($path) : unlink($path);
    }
    rmdir($dir);
}

deleteDir($tmpDir);

echo "ZIP de assets guardado en: $zipPath\n";

