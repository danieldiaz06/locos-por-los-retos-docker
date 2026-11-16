<?php
// Consulta la API de ip-api.com para obtener la ubicación de una IP
function getUserLocation($ip) {
    $url = "http://ip-api.com/json/{$ip}?fields=status,country,regionName,city,query";
    $response = @file_get_contents($url);
    $data = json_decode($response, true);

    if ($data && $data['status'] == 'success') {
        return [
            'ip'      => $data['query'],
            'country' => $data['country'],
            'region'  => $data['regionName'],
            'city'    => $data['city']
        ];
    }
    return null;
}

// Obtener la IP del usuario
$ip = $_SERVER['REMOTE_ADDR'];
echo "<h1>Server 1 - Geolocalización</h1>";
echo "IP del cliente: " . $ip . "<br>";

// Si la IP es privada (red Docker / red local) ip-api no puede localizarla,
// así que consultamos sin IP para que devuelva la IP pública de salida
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
    echo "(IP privada: se localiza la IP pública de salida)<br><br>";
    $ip = '';
}

$location = getUserLocation($ip);

if ($location) {
    echo "IP: " . $location['ip'] . "<br>";
    echo "País: " . $location['country'] . "<br>";
    echo "Región: " . $location['region'] . "<br>";
    echo "Ciudad: " . $location['city'] . "<br>";
} else {
    echo "No se pudo determinar la ubicación.";
}
?>
