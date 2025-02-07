<?php

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');

define("IIKO_HOST", "");
define("LOGIN", "");
define("PASSWORD_SHA1", "");

function iikoLogin(): ?string
{
    $authUrl = IIKO_HOST . "/resto/api/auth";

    // Параметры для авторизации
    $fields = [
        "login" => LOGIN,
        "pass"  => PASSWORD_SHA1
    ];

    // Собираем form-urlencoded
    $postData = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $authUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error || $httpCode !== 200) {
        error_log("iikoLogin() FAIL. httpCode=$httpCode, cURL error='$error', response='$response'");
        echo "<div style='color:red; margin:10px 0;'>
                iikoLogin() FAIL.<br>
                httpCode=$httpCode<br>
                cURLerror='$error'<br>
                response='$response'
              </div>";
        return null;
    }

    error_log("iikoLogin() SUCCESS. Token='" . trim($response) . "'");
    return trim($response);
}


function iikoLogout(string $token): void
{
    $logoutUrl = IIKO_HOST . "/resto/api/logout";
    $fields = ["key" => $token];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $logoutUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("iikoLogout(). httpCode=$httpCode, cURL error='$error', response='$response'");
}

function fetchAllSuppliers(string $token): array
{
    $url = IIKO_HOST . "/resto/api/suppliers?key=" . urlencode($token);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error || $httpCode !== 200) {
        error_log("fetchAllSuppliers() FAIL. httpCode=$httpCode, cURL error='$error', response='$response'");
        return [];
    }
    $data = json_decode($response, true);
    if (is_array($data)) return $data;

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response);
    if ($xml === false) {
        error_log("fetchAllSuppliers() parsing XML FAIL. response='$response'");
        return [];
    }
    $suppliers = [];
    if ($xml->getName() === "employees") {
        foreach ($xml->employee as $emp) {
            $suppliers[] = xmlToAssoc($emp);
        }
    } elseif ($xml->getName() === "employee") {
        $suppliers[] = xmlToAssoc($xml);
    }
    return $suppliers;
}

function xmlToAssoc(\SimpleXMLElement $element): array
{
    $arr = [];
    foreach ($element->children() as $child) {
        $arr[$child->getName()] = trim((string)$child);
    }
    return $arr;
}

function filterSuppliers(array $suppliers, string $searchName): array
{
    if (!$searchName) return $suppliers;
    $searchLower = mb_strtolower($searchName);
    $filtered = [];
    foreach ($suppliers as $s) {
        $nameLower = mb_strtolower($s["name"] ?? "");
        if (strpos($nameLower, $searchLower) !== false) {
            $filtered[] = $s;
        }
    }
    return $filtered;
}

function printSuppliersTable(array $suppliers): void
{
    if (empty($suppliers)) {
        echo "<p class='text-danger'>Список поставщиков пуст (или ничего не найдено).</p>";
        return;
    }
    $columns = ["id", "code", "name", "supplier", "deleted"];
    echo "<div class='table-responsive'>";
    echo "<table class='table table-striped table-bordered'>";
    echo "<thead><tr>";
    foreach ($columns as $col) {
        echo "<th>" . htmlspecialchars(strtoupper($col)) . "</th>";
    }
    echo "</tr></thead><tbody>";
    foreach ($suppliers as $s) {
        echo "<tr>";
        foreach ($columns as $col) {
            $val = $s[$col] ?? "";
            echo "<td>" . htmlspecialchars($val) . "</td>";
        }
        echo "</tr>";
    }
    echo "</tbody></table></div>";
}

$session = curl_init(); 
$token = iikoLogin();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8"/>
    <title>Список поставщиков Syrve</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-4">
    <h1 class="mb-4">Список поставщиков</h1>
    <?php if (!$token): ?>
        <div class="alert alert-danger">
            <strong>Ошибка!</strong> Не удалось авторизоваться в Syrve.
        </div>
    <?php else: ?>
        <?php
        $allSuppliers = fetchAllSuppliers($token);
        $searchName = $_GET['searchName'] ?? '';
        ?>
        <form method="GET" class="row gy-2 gx-3 align-items-center mb-3">
            <div class="col-auto">
                <label for="searchName" class="form-label">Поиск по имени:</label>
            </div>
            <div class="col-auto">
                <input type="text" name="searchName" id="searchName" class="form-control"
                       placeholder="Введите часть имени..."
                       value="<?php echo htmlspecialchars($searchName); ?>"/>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">Искать</button>
                <a href="index.php" class="btn btn-secondary">Сброс</a>
            </div>
        </form>
        <?php
        $filtered = filterSuppliers($allSuppliers, $searchName);
        printSuppliersTable($filtered);
        iikoLogout($token);
        ?>
    <?php endif; ?>
</div>
</body>
</html>
