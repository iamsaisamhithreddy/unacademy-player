<?php
function fetch_range($url, $range) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_BINARYTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            "Range: bytes=$range",
            "User-Agent: Mozilla/5.0",
            "Accept: */*"
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data ?: "";
}

function get_pdf_pages_optimized($url) {
    if(!$url) return 0;

    // first 32KB
    $data = fetch_range($url, "0-32768");

    if ($data && preg_match("/\/Count\s+(\d+)/", $data, $m))
        return (int)$m[1];

    // last 60KB
    $footer = fetch_range($url, "-60000");

    if ($footer) {
        if (preg_match("/\/Count\s+(\d+)/", $footer, $m))
            return (int)$m[1];

        $count = preg_match_all("/\/Type\s*\/Page\b/", $footer, $tmp);
        if ($count > 0) return $count;
    }

    return 0;
}

$pdf_url = $_GET['url'] ?? '';
$page_count = $pdf_url ? get_pdf_pages_optimized($pdf_url) : 0;

/* RAW MODE for player */
if (isset($_GET['raw'])) {
    header("Content-Type: text/plain");
    echo $page_count;
    exit;
}
?>
