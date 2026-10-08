<?php
declare(strict_types=1);

/**
 * ============================================================
 * ENIGMA2 Xtream Codes Picon Generator
 * TRC4 Broadcast UI Neon
 *
 * Requires:
 *   - PHP 8.x
 *   - cURL
 *   - GD
 *   - FTP extension
 *
 * Files:
 *   /config.php
 *   /index.php
 * ============================================================
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

set_time_limit(100000);
ini_set('default_socket_timeout', '30');

require_once __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

const PICON_DIR = __DIR__ . '/picon';
/*
if (!is_dir(PICON_DIR)) {
    if (!mkdir(PICON_DIR, 0775, true)) {
        die('Could not Create picon Directory');
    }
}
*/
//të mos japë warning nëse folderi krijohet nga një request tjetër në të njëjtën kohë

if (!is_dir(PICON_DIR)) {
    @mkdir(PICON_DIR, 0775, true);
}

if (!is_dir(PICON_DIR)) {
    die('Picon directory could not be created: ' . PICON_DIR);
}
//të mos japë warning nëse folderi krijohet nga një request tjetër në të njëjtën kohë


const PICON_WIDTH     = 220;
const PICON_HEIGHT    = 132;

const HTTP_TIMEOUT    = 30;
const HTTP_CONNECT    = 10;
const HTTP_RETRIES    = 3;

const FTP_TIMEOUT     = 30;

const USER_AGENT =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' .
    'AppleWebKit/537.36 (KHTML, like Gecko) ' .
    'Chrome/154.0.0.0 Safari/537.36';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
		JSON_PRETTY_PRINT
    );

    exit;
}

function ndjson(array $data): void
{
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
		JSON_PRETTY_PRINT
    ) . "\n";

    @ob_flush();
    @flush();
}

function clean(string $string): string
{
    $string = trim($string);
    $string = preg_replace('/\s+/',' ',$string);

    $string = preg_replace('/[^\p{L}\p{N}\-_ .]+/u','',$string);
    return trim($string);
}

function normalizeHost(string $host): string
{
    return rtrim(trim($host), '/');
}

function xtreamUrl(
    string $action = '',
    array $extra = []
): string {

    global $xtream_host;
    global $xtream_user;
    global $xtream_pass;

    $base = normalizeHost($xtream_host);

    $query = [
        'username' => $xtream_user,
        'password' => $xtream_pass
    ];

    if ($action !== '') {
        $query['action'] = $action;
    }

    foreach ($extra as $key => $value) {
        $query[$key] = $value;
    }

    return $base . '/player_api.php?' . http_build_query($query);
}

/*
|--------------------------------------------------------------------------
| Generic cURL GET
|--------------------------------------------------------------------------
*/

function curlGet(
    string $url,
    bool $json = false
): array {

    $lastError = '';

    for ($attempt = 1; $attempt <= HTTP_RETRIES; $attempt++) {

        $ch = curl_init();

        curl_setopt_array(
            $ch,
            [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 8,
                CURLOPT_CONNECTTIMEOUT => HTTP_CONNECT,
                CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
                CURLOPT_USERAGENT      => USER_AGENT,
                CURLOPT_HTTPHEADER     => [
                    'Accept: ' . (
                        $json
                            ? 'application/json,text/plain,*/*'
                            : '*/*'
                    ),
                    'Accept-Language: en-US,en;q=0.9',
                    'Cache-Control: no-cache',
                    'Pragma: no-cache',
                    'Connection: keep-alive'
                ],

                CURLOPT_ENCODING       => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS
            ]
        );

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $effectiveUrl = (string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($body !== false && $httpCode >= 200 && $httpCode < 300) {

            return [
                'ok'            => true,
                'body'          => $body,
                'http_code'     => $httpCode,
                'effective_url' => $effectiveUrl,
                'error'         => ''
            ];
        }

        $lastError = $error !== '' ? $error : "HTTP {$httpCode}";

        if ($attempt < HTTP_RETRIES) {
            usleep(350000 * $attempt);
        }
    }

    return [
        'ok'            => false,
        'body'          => '',
        'http_code'     => $httpCode ?? 0,
        'effective_url' => '',
        'error'         => $lastError
    ];
}

/*
|--------------------------------------------------------------------------
| Xtream categories
|--------------------------------------------------------------------------
*/

function getXtreamCategories(): array
{
    $url = xtreamUrl('get_live_categories');

    $result = curlGet($url, true);

    if (!$result['ok']) {
        return [];
    }

    $data = json_decode(
        $result['body'],
        true
    );

    if (!is_array($data)) {
        return [];
    }

    return $data;
}

/*
|--------------------------------------------------------------------------
| Xtream streams
|--------------------------------------------------------------------------
*/

function getXtreamStreams(string|int $categoryId): array {

    $url = xtreamUrl(
        'get_live_streams',
        [
            'category_id' => (string)$categoryId
        ]
    );

    $result = curlGet($url, true);

    if (!$result['ok']) {
        return [];
    }

    $data = json_decode(
        $result['body'],
        true
    );

    return is_array($data) ? $data : [];
}

/*
|--------------------------------------------------------------------------
| Download remote picon with cURL
|--------------------------------------------------------------------------
*/

function curlDownloadImage(string $url): array {

    $url = trim($url);

    if ($url === '') {
        return [
            'ok'        => false,
            'data'      => '',
            'http_code' => 0,
            'error'     => 'Empty image URL.'
        ];
    }

    $lastError = '';
    $lastCode  = 0;

    for (
        $attempt = 1;
        $attempt <= HTTP_RETRIES;
        $attempt++
    ) {

        $ch = curl_init();

        curl_setopt_array(
            $ch,
            [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_CONNECTTIMEOUT => HTTP_CONNECT,
                CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
                CURLOPT_USERAGENT      => USER_AGENT,
                CURLOPT_REFERER       => 'https://www.google.com/',
                CURLOPT_HTTPHEADER     => [
                    'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9',
                    'Cache-Control: no-cache',
                    'Pragma: no-cache',
                    'Connection: keep-alive'
                ],

                CURLOPT_ENCODING       => '',
                CURLOPT_AUTOREFERER    => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS
            ]
        );

        $data = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $lastCode = $httpCode;

        if (
            $data !== false &&
            $httpCode >= 200 &&
            $httpCode < 300 &&
            strlen($data) > 20
        ) {

            return [
                'ok'           => true,
                'data'         => $data,
                'http_code'    => $httpCode,
                'content_type' => $contentType,
                'error'        => ''
            ];
        }

        $lastError = $error !== '' ? $error : "HTTP {$httpCode}";

        if ($attempt < HTTP_RETRIES) {
            usleep(500000 * $attempt);
        }
    }

    return [
        'ok'           => false,
        'data'         => '',
        'http_code'    => $lastCode,
        'content_type' => '',
        'error'        => $lastError
    ];
}

/*
|--------------------------------------------------------------------------
| Resize / convert picon
|--------------------------------------------------------------------------
*/

function resizePicon(string $sourceFile,string $destinationFile): array {

    if (!extension_loaded('gd')) {
        return [
            'ok'    => false,
            'error' => 'PHP GD extension is not enabled.'
        ];
    }

    $info = @getimagesize($sourceFile);

    if (!$info) {
        return [
            'ok'    => false,
            'error' => 'Downloaded file is not a valid image.'
        ];
    }

    $width  = (int)$info[0];
    $height = (int)$info[1];
    $type   = (int)$info[2];

    if ($width < 1 || $height < 1) {
        return [
            'ok'    => false,
            'error' => 'Invalid image dimensions.'
        ];
    }

    switch ($type) {

        case IMAGETYPE_JPEG:
            $source = @imagecreatefromjpeg($sourceFile);
            break;

        case IMAGETYPE_PNG:
            $source = @imagecreatefrompng($sourceFile);
            break;

        case IMAGETYPE_WEBP:
            if (!function_exists('imagecreatefromwebp')) {
                return [
                    'ok'    => false,
                    'error' => 'WEBP support is not enabled in GD.'
                ];
            }

            $source = @imagecreatefromwebp($sourceFile);
            break;

        case IMAGETYPE_GIF:
            $source = @imagecreatefromgif($sourceFile);
            break;

        default:
            return [
                'ok'    => false,
                'error' => 'Unsupported image format.'
            ];
    }

    if (!$source) {
        return [
            'ok'    => false,
            'error' => 'Could not read image.'
        ];
    }

    /*
     * Keep aspect ratio and fit inside 220x132.
     */

    $ratio = min(
        PICON_WIDTH / $width,
        PICON_HEIGHT / $height
    );

    $newWidth  = max(1, (int)round($width * $ratio));
    $newHeight = max(1, (int)round($height * $ratio));

    $canvas = imagecreatetruecolor(
        PICON_WIDTH,
        PICON_HEIGHT
    );

    if (!$canvas) {
        imagedestroy($source);

        return [
            'ok'    => false,
            'error' => 'Could not create output canvas.'
        ];
    }

    /*
     * Transparent background.
     */

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);

    $transparent = imagecolorallocatealpha(
        $canvas,
        0,
        0,
        0,
        127
    );

    imagefill(
        $canvas,
        0,
        0,
        $transparent
    );

    $dstX = (PICON_WIDTH - $newWidth) / 2;
    $dstY = (PICON_HEIGHT - $newHeight) / 2;

    imagecopyresampled(
        $canvas,
        $source,
        (int)$dstX,
        (int)$dstY,
        0,
        0,
        $newWidth,
        $newHeight,
        $width,
        $height
    );

    $saved = imagepng(
        $canvas,
        $destinationFile,
        6
    );

    imagedestroy($source);
    imagedestroy($canvas);

    if (!$saved) {
        return [
            'ok'    => false,
            'error' => 'Could not save PNG.'
        ];
    }

    return [
        'ok'    => true,
        'error' => ''
    ];
}

/*
|--------------------------------------------------------------------------
| FTP
|--------------------------------------------------------------------------
*/

function connectFTP(): array
{
    global $ftp_server;
    global $ftp_user;
    global $ftp_pass;

    if (
        trim($ftp_server) === '' ||
        trim($ftp_user) === ''
    ) {
        return [
            'ok'   => false,
            'conn' => null,
            'error' => 'FTP Configuration is Empty.'
        ];
    }

    $conn = @ftp_connect(
        $ftp_server,
        21,
        FTP_TIMEOUT
    );

    if (!$conn) {
        return [
            'ok'   => false,
            'conn' => null,
            'error' => 'Could not Connect to FTP Server'
        ];
    }

    $login = @ftp_login(
        $conn,
        $ftp_user,
        $ftp_pass
    );

    if (!$login) {
        @ftp_close($conn);

        return [
            'ok'   => false,
            'conn' => null,
            'error' => 'FTP Authentication Failed'
        ];
    }

    @ftp_pasv($conn, true);

    return [
        'ok'    => true,
        'conn'  => $conn,
        'error' => ''
    ];
}

function ftpUploadPicon(
    $ftp,
    string $localFile,
    string $filename
): bool {

    if (!$ftp) {
        return false;
    }

    return @ftp_put($ftp,'/usr/share/enigma2/picon/' . $filename,$localFile,FTP_BINARY);
}

/*
|--------------------------------------------------------------------------
| Clear old picons
|--------------------------------------------------------------------------
*/

function clearPicons(
    $ftp
): array {

    $deleted = 0;
    $failed  = 0;

    if (!$ftp) {
        return [
            'deleted' => 0,
            'failed'  => 0
        ];
    }

    $files = @ftp_nlist($ftp,'/usr/share/enigma2/picon/');

    if (!is_array($files)) {
        return [
            'deleted' => 0,
            'failed'  => 0
        ];
    }

    foreach ($files as $file) {

        $basename = basename($file);

        if (
            $basename === '.' ||
            $basename === '..'
        ) {
            continue;
        }

        /*
         * Preserve files beginning with "1_"
         * exactly like the original generator.
         */
        if (str_starts_with($basename, '1_')) {
            continue;
        }

        if (@ftp_delete($ftp, $file)) {
            $deleted++;
        } else {
            $failed++;
        }
    }

    return [
        'deleted' => $deleted,
        'failed'  => $failed
    ];
}

/*
|--------------------------------------------------------------------------
| AJAX: categories
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['ajax']) &&
    $_GET['ajax'] === 'categories'
) {

    $categories = getXtreamCategories();

    jsonResponse([
        'ok'         => true,
        'categories' => $categories,
        'count'      => count($categories)
    ]);
}

/*
|--------------------------------------------------------------------------
| AJAX: FTP test
|--------------------------------------------------------------------------
*/

if (isset($_GET['ajax']) && $_GET['ajax'] === 'ftp_test') {
    $ftp = connectFTP();

    if (!$ftp['ok']) {
        jsonResponse([
            'ok'    => false,
            'error' => $ftp['error']
        ]);
    }

    @ftp_close($ftp['conn']);

    jsonResponse([
        'ok' => true
    ]);
}

/*
|--------------------------------------------------------------------------
| AJAX: GENERATOR
|--------------------------------------------------------------------------
*/

if (isset($_GET['ajax']) &&$_GET['ajax'] === 'generate') {
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }

    ob_implicit_flush(true);
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('X-Accel-Buffering: no');

    /*
     * Read POST.
     */

    $raw = file_get_contents('php://input');

    $input = json_decode($raw ?: '{}',true);

    if (!is_array($input)) {
        $input = [];
    }

    $selectedCategories = $input['categories'] ?? [];

    $uploadFTP = !empty($input['uploadFTP']);

    $clearOld = !empty($input['clearPicons']);

    if (!is_array($selectedCategories)) {
        $selectedCategories = [];
    }

    /*
     * Normalize category IDs.
     */

    $selectedCategories = array_values(
        array_unique(
            array_map(
                'strval',
                $selectedCategories
            )
        )
    );

    if (!$selectedCategories) {

        ndjson([
            'event'   => 'fatal',
            'message' => 'No categories selected.'
        ]);

        exit;
    }

    ndjson([
        'event'   => 'start',
        'message' => 'Generator started.',
        'categories' => count($selectedCategories)
    ]);

    /*
     * FTP connection.
     */

    $ftp = null;

    if ($uploadFTP || $clearOld) {

        $ftpResult = connectFTP();

        if (!$ftpResult['ok']) {
            ndjson([
                'event'   => 'fatal',
                'message' => $ftpResult['error']
            ]);

            exit;
        }

        $ftp = $ftpResult['conn'];

        ndjson([
            'event'   => 'ftp',
            'status'  => 'connected',
            'message' => 'FTP connected.'
        ]);
    }

    /*
     * Clear old picons.
     */

    if ($clearOld && $ftp) {
        ndjson([
            'event'   => 'clear_start',
            'message' => 'Clearing old picons...'
        ]);

        $clearResult = clearPicons($ftp);

        ndjson([
            'event'   => 'clear_done',
            'deleted' => $clearResult['deleted'],
            'failed'  => $clearResult['failed'],
            'message' =>
                'Deleted ' .
                $clearResult['deleted'] .
                ' old picons.'
        ]);
    }

    /*
     * Get streams.
     */

    $allStreams = [];

    foreach ($selectedCategories as $categoryId) {

        $streams = getXtreamStreams($categoryId);

        ndjson([
            'event'       => 'category',
            'category_id' => $categoryId,
            'count'       => count($streams),
            'message' =>
                'Category ' .
                $categoryId .
                ': ' .
                count($streams) .
                ' streams.'
        ]);

        foreach ($streams as $stream) {

            if (!is_array($stream)) {
                continue;
            }

            $allStreams[] = $stream;
        }
    }

    /*
     * Remove duplicates by stream_id.
     */

    $uniqueStreams = [];

    foreach ($allStreams as $stream) {
        $streamId = (string)($stream['stream_id'] ?? '');
        $name = (string)($stream['name'] ?? '');
        $key = $streamId !== '' ? $streamId : md5($name . '|' . ($stream['stream_icon'] ?? ''));
        $uniqueStreams[$key] = $stream;
    }

    $allStreams = array_values($uniqueStreams);

    $total = count($allStreams);

    ndjson([
        'event'   => 'queue',
        'total'   => $total,
        'message' => "Found {$total} unique streams."
    ]);

    if ($total === 0) {

        ndjson([
            'event'   => 'fatal',
            'message' => 'No streams found in selected categories.'
        ]);

        if ($ftp) {
            @ftp_close($ftp);
        }

        exit;
    }

    /*
     * Ensure local picon directory.
     */

    if (!is_dir(PICON_DIR)) {

        if (!@mkdir(PICON_DIR, 0775, true)) {

            ndjson([
                'event'   => 'fatal',
                'message' => 'Could not create local picon directory.'
            ]);

            if ($ftp) {
                @ftp_close($ftp);
            }

            exit;
        }
    }

    /*
     * Counters.
     */

    $success = 0;
    $failed  = 0;
    $ftpOk   = 0;
    $ftpFail = 0;

    /*
     * Process.
     */

    foreach ($allStreams as $index => $stream) {

        $current = $index + 1;

        $name = trim(
            (string)(
                $stream['name']
                ?? 'Unknown Channel'
            )
        );

        $icon = trim(
            (string)(
                $stream['stream_icon']
                ?? ''
            )
        );

        $start = microtime(true);

        ndjson([
            'event'   => 'item',
            'current' => $current,
            'total'   => $total,
            'name'    => $name,
            'url'     => $icon,
            'message' =>
                "[$current/$total] Getting picon: {$name}"
        ]);

        if ($icon === '') {

            $failed++;

            ndjson([
                'event'      => 'error',
                'current'    => $current,
                'total'      => $total,
                'name'       => $name,
                'error'      => 'Empty stream_icon.',
                'success'    => $success,
                'failed'     => $failed,
                'ftp_ok'     => $ftpOk,
                'ftp_failed' => $ftpFail
            ]);

            continue;
        }

        /*
         * Download with cURL.
         */

        $download = curlDownloadImage($icon);

        if (!$download['ok']) {

            $failed++;

            ndjson([
                'event'      => 'error',
                'current'    => $current,
                'total'      => $total,
                'name'       => $name,
                'http_code'  => $download['http_code'],
                'error'      => $download['error'],
                'success'    => $success,
                'failed'     => $failed,
                'ftp_ok'     => $ftpOk,
                'ftp_failed' => $ftpFail
            ]);

            continue;
        }

        /*
         * Temporary input.
         */

        $tmpFile = tempnam(
            sys_get_temp_dir(),
            'picon_'
        );

        if (!$tmpFile) {

            $failed++;

            ndjson([
                'event'      => 'error',
                'current'    => $current,
                'total'      => $total,
                'name'       => $name,
                'error'      => 'Could not create temporary file.',
                'success'    => $success,
                'failed'     => $failed,
                'ftp_ok'     => $ftpOk,
                'ftp_failed' => $ftpFail
            ]);

            continue;
        }

        if (@file_put_contents($tmpFile,$download['data']) === false) {

            @unlink($tmpFile);

            $failed++;

            ndjson([
                'event'      => 'error',
                'current'    => $current,
                'total'      => $total,
                'name'       => $name,
                'error'      => 'Could not save downloaded image.',
                'success'    => $success,
                'failed'     => $failed,
                'ftp_ok'     => $ftpOk,
                'ftp_failed' => $ftpFail
            ]);

            continue;
        }

        /*
         * Safe filename.
         */

        $safeName = clean($name);

        if ($safeName === '') {
            $safeName = 'channel_' . $current;
        }

        /*
         * Enigma2 picon names are normally channel based.
         *
         * Keep PNG.
         */
        $filename =
            $safeName . '.png';

        $localFile =
            PICON_DIR . DIRECTORY_SEPARATOR . $filename;

        /*
         * Resize.
         */

        $resize = resizePicon(
            $tmpFile,
            $localFile
        );

        @unlink($tmpFile);

        if (!$resize['ok']) {

            $failed++;

            ndjson([
                'event'      => 'error',
                'current'    => $current,
                'total'      => $total,
                'name'       => $name,
                'error'      => $resize['error'],
                'success'    => $success,
                'failed'     => $failed,
                'ftp_ok'     => $ftpOk,
                'ftp_failed' => $ftpFail
            ]);

            continue;
        }

        /*
         * Upload FTP.
         */

        $uploaded = null;

        if ($uploadFTP && $ftp) {

            $uploaded = ftpUploadPicon(
                $ftp,
                $localFile,
                $filename
            );

            if ($uploaded) {
                $ftpOk++;
            } else {
                $ftpFail++;
            }
        }

        $success++;

        $duration = round(
            (microtime(true) - $start) * 1000
        );

        ndjson([
            'event'       => 'success',
            'current'     => $current,
            'total'       => $total,
            'name'        => $name,
            'filename'    => $filename,
            'http_code'   => $download['http_code'],
            'ftp'         => $uploaded,
            'duration_ms' => $duration,
            'success'     => $success,
            'failed'      => $failed,
            'ftp_ok'      => $ftpOk,
            'ftp_failed'  => $ftpFail,
            'message'     => $uploaded === true ? "✓ {$name} → {$filename} → FTP" : "✓ {$name} → {$filename}"
        ]);

        /*
         * Remove local generated file if FTP upload
         * was requested and successful.
         */
        if (
            $uploadFTP &&
            $uploaded === true &&
            is_file($localFile)
        ) {
            @unlink($localFile);
        }
    }

    if ($ftp) {
        @ftp_close($ftp);
    }

    ndjson([
        'event'       => 'done',
        'total'       => $total,
        'success'     => $success,
        'failed'      => $failed,
        'ftp_ok'      => $ftpOk,
        'ftp_failed'  => $ftpFail,
        'message'     => "Completed: {$success} OK / {$failed} Failed"
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Initial page information
|--------------------------------------------------------------------------
*/

$categories = getXtreamCategories();
$xtreamConnected = count($categories) > 0;
$ftpConfigured = trim($ftp_server) !== '' && trim($ftp_user) !== '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TRC4 • ENIGMA2 Xtream Codes Picon Generator</title>
<link rel="shortcut icon" href="https://kodi.al/favicon.ico"/>
<style>
:root {
    --bg: #020403;
    --bg2: #050806;
    --panel: #080d09;
    --panel2: #0b120c;

    --lime: #b6ff00;
    --lime2: #76ff00;
    --lime3: #d7ff73;

    --text: #d9ffd0;
    --muted: #71906c;

    --red: #ff3158;
    --orange: #ffb000;
    --cyan: #00e5ff;

    --border: rgba(182,255,0,.18);

    --shadow:
        0 0 30px rgba(120,255,0,.08),
        inset 0 0 30px rgba(120,255,0,.015);
}

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    min-height: 100%;
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(120,255,0,.06),
            transparent 30%
        ),
        radial-gradient(
            circle at 100% 100%,
            rgba(0,255,170,.035),
            transparent 30%
        ),
        var(--bg);

    color: var(--text);

    font-family:
        Inter,
        Segoe UI,
        Arial,
        sans-serif;
}

body {
    padding: 18px;
}

button,
input {
    font: inherit;
}

button {
    cursor: pointer;
}

.app {
    width: min(1500px, 100%);
    margin: auto;
}

.header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: 18px 20px;

    border: 1px solid var(--border);
    border-radius: 14px;

    background:
        linear-gradient(
            135deg,
            rgba(182,255,0,.055),
            rgba(0,0,0,.4)
        );

    box-shadow: var(--shadow);

    margin-bottom: 14px;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
}

.logo {
    width: 52px;
    height: 42px;

    display: grid;
    place-items: center;

    border: 1px solid rgba(182,255,0,.45);
    border-radius: 10px;

    color: var(--lime);

    box-shadow:
        0 0 15px rgba(182,255,0,.18),
        inset 0 0 15px rgba(182,255,0,.04);

    font-weight: 900;
}

.title {
    font-size: 17px;
    font-weight: 900;
    letter-spacing: .12em;
    color: var(--lime);
}

.subtitle {
    color: var(--muted);
    font-size: 11px;
    margin-top: 3px;
}

.status {
    display: flex;
    align-items: center;
    gap: 8px;

    font-size: 11px;
    color: var(--muted);
}

.dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;

    background: var(--red);

    box-shadow:
        0 0 10px rgba(255,49,88,.55);
}

.dot.ok {
    background: var(--lime);

    box-shadow:
        0 0 12px rgba(182,255,0,.7);
}

.grid {
    display: grid;
    grid-template-columns: 420px 1fr;
    gap: 14px;
}

@media (max-width: 950px) {
    .grid {
        grid-template-columns: 1fr;
    }
}

.panel {
    background:
        linear-gradient(
            180deg,
            rgba(10,17,11,.96),
            rgba(3,6,4,.98)
        );

    border: 1px solid var(--border);
    border-radius: 14px;

    box-shadow: var(--shadow);

    overflow: hidden;
}

.panel-head {
    padding: 13px 15px;

    border-bottom: 1px solid var(--border);

    display: flex;
    align-items: center;
    justify-content: space-between;
}

.panel-title {
    color: var(--lime);
    font-weight: 800;
    font-size: 12px;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.panel-body {
    padding: 14px;
}

.stats {
    display: grid;
    grid-template-columns:
        repeat(4, 1fr);

    gap: 8px;

    margin-bottom: 12px;
}

.stat {
    padding: 11px;

    border:
        1px solid
        rgba(182,255,0,.12);

    border-radius: 9px;

    background: rgba(0,0,0,.3);
}

.stat-label {
    color: var(--muted);
    font-size: 9px;
    text-transform: uppercase;
}

.stat-value {
    margin-top: 4px;

    font-size: 19px;
    font-weight: 900;

    color: var(--lime);
}

.controls {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;

    margin-bottom: 10px;
}

.btn {
    border: 1px solid rgba(182,255,0,.25);

    background:
        linear-gradient(
            180deg,
            rgba(182,255,0,.09),
            rgba(182,255,0,.025)
        );

    color: var(--lime);

    padding: 9px 12px;

    border-radius: 8px;

    font-size: 10px;
    font-weight: 900;

    letter-spacing: .05em;

    transition:
        .15s ease;
}

.btn:hover {
    border-color: rgba(182,255,0,.65);

    box-shadow:
        0 0 15px rgba(182,255,0,.14);
}

.btn.primary {
    background:
        linear-gradient(
            135deg,
            rgba(182,255,0,.22),
            rgba(118,255,0,.08)
        );

    border-color:
        rgba(182,255,0,.6);

    box-shadow:
        0 0 18px rgba(182,255,0,.1);
}

.btn.danger {
    color: #ff5877;
    border-color: rgba(255,49,88,.28);
}

.btn:disabled {
    opacity: .35;
    cursor: not-allowed;
}

.checkline {
    display: flex;
    align-items: center;
    gap: 9px;

    padding: 10px 11px;

    margin-top: 8px;

    border: 1px solid rgba(182,255,0,.10);
    border-radius: 8px;

    background: rgba(0,0,0,.22);

    color: var(--text);

    font-size: 11px;
}

.checkline input {
    accent-color: var(--lime);
}

.categories {
    height: 440px;
    overflow-y: auto;

    border:
        1px solid
        rgba(182,255,0,.12);

    border-radius: 10px;

    background: #010201;
}

.category {
    display: flex;
    align-items: center;
    gap: 9px;

    padding: 9px 10px;

    border-bottom:
        1px solid
        rgba(182,255,0,.06);

    transition: background .12s ease;
}

.category:hover {
    background:
        rgba(182,255,0,.055);
}

.category input {
    accent-color: var(--lime);
}

.category-name {
    flex: 1;

    font-size: 11px;
    color: #c9eec1;

    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.category-id {
    color: #526a50;
    font-size: 9px;
}

.progress-wrap {
    margin-bottom: 12px;
}

.progress-top {
    display: flex;
    justify-content: space-between;

    color: var(--muted);
    font-size: 10px;

    margin-bottom: 6px;
}

.progress {
    height: 9px;

    overflow: hidden;

    border-radius: 999px;

    border: 1px solid
        rgba(182,255,0,.15);

    background: #010201;
}

.progress-bar {
    width: 0%;
    height: 100%;

    background:
        linear-gradient(
            90deg,
            var(--lime2),
            var(--lime)
        );

    box-shadow:
        0 0 15px rgba(182,255,0,.6);

    transition: width .2s ease;
}

.console {
    height: 560px;

    overflow-y: auto;

    padding: 12px;

    background:
        radial-gradient(
            circle at 50% 0%,
            rgba(182,255,0,.025),
            transparent 45%
        ),
        #010201;

    font-family:
        Consolas,
        Monaco,
        monospace;

    font-size: 10px;
}

.log {
    padding: 6px 7px;

    border-left: 2px solid
        rgba(182,255,0,.18);

    margin-bottom: 4px;

    line-height: 1.45;

    color: #9fbc99;
}

.log.ok {
    color: #b9ff80;
    border-left-color: var(--lime);
}

.log.error {
    color: #ff6884;
    border-left-color: var(--red);
}

.log.info {
    color: #a9dca0;
}

.log.ftp {
    color: #71f6ff;
    border-left-color: var(--cyan);
}

.empty {
    display: grid;
    place-items: center;

    min-height: 300px;

    color: #456043;

    font-size: 11px;
}

.toast-container {
    position: fixed;

    right: 18px;
    bottom: 18px;

    z-index: 9999;

    display: flex;
    flex-direction: column;

    gap: 8px;
}

.toast {
    width: min(390px, calc(100vw - 36px));

    padding: 12px 14px;

    border-radius: 10px;

    border: 1px solid
        rgba(182,255,0,.28);

    background:
        rgba(4,9,5,.97);

    box-shadow:
        0 0 25px rgba(182,255,0,.12);

    color: var(--text);

    font-size: 11px;

    animation:
        toastIn .2s ease;
}

.toast.error {
    border-color:
        rgba(255,49,88,.4);

    color: #ff9caf;
}

@keyframes toastIn {
    from {
        transform: translateY(10px);
        opacity: 0;
    }

    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.loading {
    display: inline-flex;
    align-items: center;
    gap: 7px;
}

.spinner {
    width: 10px;
    height: 10px;

    border: 2px solid
        rgba(182,255,0,.2);

    border-top-color:
        var(--lime);

    border-radius: 50%;

    animation:
        spin .7s linear infinite;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.small {
    color: var(--muted);
    font-size: 9px;
    line-height: 1.5;
}

.footer {
    margin-top: 10px;

    text-align: center;

    color: #30432e;

    font-size: 9px;
}
</style>
</head>

<body>
<div class="app">

    <header class="header">
        <div class="brand">
            <div class="logo">TRC4</div>

            <div>
                <div class="title">
                    ENIGMA2 Xtream Codes Picon Generator
                </div>

                <div class="subtitle">
                    Xtream Codes → Picon → FTP
                </div>
            </div>
        </div>

        <div class="status">
            <span
                id="statusDot"
                class="dot <?= $xtreamConnected ? 'ok' : '' ?>">
			</span>

            <span id="statusText">
                <?= $xtreamConnected
                    ? 'Xtream Codes: Connected'
                    : 'Xtream Codes: Offline' ?>
            </span>
        </div>
    </header>

    <div class="grid">
        <!-- LEFT -->
        <section class="panel">
            <div class="panel-head">

                <div class="panel-title">
                    Generator
                </div>

                <div id="selectedCount" class="small">0 Selected</div>
            </div>

            <div class="panel-body">
                <div class="stats">

                    <div class="stat">
                        <div class="stat-label">
                            Categories
                        </div>

                        <div id="categoryCount" class="stat-value">
                            <?= count($categories) ?>
                        </div>
                    </div>

                    <div class="stat">
                        <div class="stat-label">
                            Selected
                        </div>

                        <div id="selectedStat" class="stat-value">
                            0
                        </div>
                    </div>

                    <div class="stat">
                        <div class="stat-label">
                            OK
                        </div>

                        <div id="okStat" class="stat-value">
                            0
                        </div>
                    </div>

                    <div class="stat">
                        <div class="stat-label">
                            Failed
                        </div>

                        <div id="failedStat" class="stat-value">
                            0
                        </div>
                    </div>

                </div>

                <div class="controls">

                    <button type="button" class="btn" onclick="selectAll()">
                        Select All
                    </button>

                    <button type="button" class="btn" onclick="clearSelection()">
                        Clear
                    </button>

                    <button type="button" class="btn" onclick="reloadCategories()" >
                        ↻ Refresh
                    </button>
                </div>

                <div id="categories" class="categories">

                    <?php if (!$categories): ?>

                        <div class="empty">
                            No Xtream Categories Found
                        </div>

                    <?php else: ?>
                        <?php foreach ($categories as $category): ?>

                            <?php
                            $id = (string)( $category['category_id'] ?? '');
                            $name = (string)( $category['category_name'] ?? 'Unknown');
                            ?>

                            <label class="category">

                                <input type="checkbox" name="category" value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" onchange="updateSelected()">

                                <span class="category-name">
                                    <?= htmlspecialchars($name,ENT_QUOTES,'UTF-8') ?>
                                </span>

                                <span class="category-id">
                                    #<?= htmlspecialchars($id,ENT_QUOTES,'UTF-8') ?>
                                </span>

                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <label class="checkline">

                    <input type="checkbox" id="uploadFTP"
                        <?= $ftpConfigured ? '' : 'disabled' ?>
                    >

                    <span>
                        Upload Generated Picons to Enigma2 FTP
                    </span>

                </label>

                <label class="checkline">
                    <input type="checkbox" id="clearPicons" <?= $ftpConfigured ? '' : 'disabled' ?>>

                    <span>
                        Clear Old Picons Before Generation
                    </span>
                </label>

                <?php if (!$ftpConfigured): ?>
                    <div class="small" style="margin-top:8px;color:#ffb000">
                        FTP is Not Configured
                    </div>
                <?php endif; ?>

                <div class="controls" style="margin-top:12px">

                    <button id="generateBtn" type="button" class="btn primary" onclick="startGenerator()">
                        ⚡ Generate Picons
                    </button>

                    <button id="stopBtn" type="button" class="btn danger" onclick="stopGenerator()" disabled>
                        ■ Stop
                    </button>
                </div>

                <div class="small">
                    Refreshing this page does not start the generator.
                    Select categories first, then press Generate.
                </div>
            </div>
        </section>

        <!-- RIGHT -->
        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">
                    Live Console
                </div>

                <div id="runStatus" class="loading" style="display:none">
                    <span class="spinner"></span>
                    Running
                </div>
            </div>

            <div class="panel-body">
                <div class="progress-wrap">
                    <div class="progress-top">

                        <span id="progressText">
                            Ready
                        </span>

                        <span id="progressPercent">
                            0%
                        </span>
                    </div>

                    <div class="progress">
                        <div id="progressBar" class="progress-bar">
						</div>
                    </div>
                </div>

                <div class="stats">
                    <div class="stat">
                        <div class="stat-label">
                            Processed
                        </div>

                        <div id="processedStat" class="stat-value">
                            0
                        </div>
                    </div>

                    <div class="stat">
                        <div class="stat-label">
                            OK
                        </div>

                        <div id="consoleOkStat" class="stat-value">
                            0
                        </div>
                    </div>

                    <div class="stat">
                        <div class="stat-label">
                            Failed
                        </div>

                        <div id="consoleFailedStat" class="stat-value">
                            0
                        </div>
                    </div>

                    <div class="stat">
                        <div class="stat-label">
                            FTP
                        </div>

                        <div id="ftpStat" class="stat-value">
                            0
                        </div>
                    </div>
                </div>

                <div id="console" class="console">
                    <div class="log info">
                        TRC4 Picon Generator Ready
                    </div>

                    <div class="log info">
                        Select Categories And Press Generate Picons
                    </div>
                </div>
            </div>
        </section>
    </div>

    <div class="footer">
        TRC4 Broadcast • Enigma2 Picon Generator
    </div>
</div>

<div id="toastContainer" class="toast-container">
</div>

<script>

let controller = null;
let running = false;

let stats = {
    total: 0,
    processed: 0,
    ok: 0,
    failed: 0,
    ftp: 0
};

function $(id) {
    return document.getElementById(id);
}

function toast(message, error = false) {
    const container = $('toastContainer');
    const el = document.createElement('div');
    el.className = 'toast' + (error ? ' error' : '');
    el.textContent = message;
    container.appendChild(el);
    setTimeout(() => {
        el.remove();
    }, 4500);
}

function addLog(message, type = 'info') {
    const consoleEl = $('console');
    const line = document.createElement('div');
    line.className = 'log ' + type;
    line.textContent = message;
    consoleEl.appendChild(line);
    consoleEl.scrollTop = consoleEl.scrollHeight;
}

function selectedCategories() {

    return Array.from(
        document.querySelectorAll(
            'input[name="category"]:checked'
        )
    ).map(
        input => input.value
    );
}

function updateSelected() {
    const count = selectedCategories().length;
    $('selectedCount').textContent = count + ' selected';
    $('selectedStat').textContent = count;
}

function selectAll() {
    document.querySelectorAll('input[name="category"]')
        .forEach(
            input => input.checked = true
        );
    updateSelected();
}

function clearSelection() {

    document.querySelectorAll('input[name="category"]')
        .forEach(
            input => input.checked = false
        );

    updateSelected();
}

function setRunning(value) {
    running = value;
    $('generateBtn').disabled = value;
    $('stopBtn').disabled = !value;
    $('runStatus').style.display = value ? 'inline-flex' : 'none';
}

function resetStats() {
    stats = {
        total: 0,
        processed: 0,
        ok: 0,
        failed: 0,
        ftp: 0
    };

    $('processedStat').textContent = '0';
    $('consoleOkStat').textContent = '0';
    $('consoleFailedStat').textContent = '0';
    $('ftpStat').textContent = '0';
    $('okStat').textContent = '0';
    $('failedStat').textContent = '0';
    $('progressBar').style.width = '0%';
    $('progressPercent').textContent = '0%';
    $('progressText').textContent = 'Ready';
}

function updateProgress(current,total) {

    if (!total) {
        return;
    }

    const percent = Math.min(100, Math.round((current / total) * 100));
    $('progressBar').style.width = percent + '%';
    $('progressPercent').textContent = percent + '%';
    $('progressText').textContent = current + ' / ' + total;
}

async function reloadCategories() {

    if (running) {
		toast('Stop the Generator First', true);
        return;
    }

    $('categories').innerHTML =
        '<div class="empty">' +
        '<span class="loading">' +
        '<span class="spinner"></span>' +
        'Loading categories...' +
        '</span>' +
        '</div>';

    try {
        const response = await fetch('?ajax=categories&ts=' + Date.now(),
		{
                    cache: 'no-store'
                }
            );

        const data = await response.json();
        if (!data.ok) {
            throw new Error('Category Request Failed.'
            );
        }

        renderCategories(data.categories || []);
        $('categoryCount').textContent = data.count || 0;
        $('statusDot').classList.toggle('ok',(data.count || 0) > 0);
        $('statusText').textContent = (data.count || 0) > 0 ? 'Xtream Codes: Connected' : 'Xtream Codes: Offline';

        addLog('Categories Refreshed: ' + (data.count || 0), 'info');
        toast('Categories Refreshed');

    } catch (error) {
        $('categories').innerHTML =
            '<div class="empty">' +
            'Could Not Load Categories.' +
            '</div>';

        addLog('Category Error: ' + error.message, 'error');
        toast(error.message,true);
    }
}

function renderCategories(categories) {

    const container = $('categories');
    container.innerHTML = '';

    if (!categories.length) {
        container.innerHTML =
            '<div class="empty">' +
            'No Xtream Categories Found' +
            '</div>';
        return;
    }

    categories.forEach(category => {
        const id = String(category.category_id ?? '');
        const name = String(category.category_name ?? 'Unknown');

        const label = document.createElement('label');

        label.className = 'category';

        label.innerHTML =
            `
            <input
                type="checkbox"
                name="category"
                value="${escapeHtml(id)}"
            >

            <span class="category-name">
                ${escapeHtml(name)}
            </span>

            <span class="category-id">
                #${escapeHtml(id)}
            </span>
            `;

        container.appendChild(label);
    });

    document.querySelectorAll(
	'input[name="category"]'
        )
        .forEach(
            input =>
                input.addEventListener('change',updateSelected
                )
        );

    updateSelected();
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function handleEvent(data) {

    if (!data || !data.event) {
        return;
    }

    switch (data.event) {
        case 'start':
            addLog('▶ ' + data.message, 'info');
            break;

        case 'ftp':
            addLog('FTP ✓ ' + data.message, 'ftp');
            break;

        case 'clear_start':
            addLog('Clear → ' +data.message,'info');
            break;

        case 'clear_done':
            addLog('Clear ✓ ' +data.message,'ok');
            break;

        case 'category':
            addLog('Category #' + data.category_id + ' → ' + data.count + ' Streams', 'info');
            break;

        case 'queue':
            stats.total = Number(data.total || 0);
            addLog('QUEUE → ' + stats.total + ' unique streams', 'info');
            break;

        case 'item':
            updateProgress(
                Number(data.current || 0),
                Number(data.total || 0)
            );

            addLog(data.message || '', 'info');
            break;

        case 'success':
            stats.processed = Number(data.current || 0);
            stats.ok = Number(data.success || 0);
            stats.failed = Number(data.failed || 0);
            stats.ftp = Number(data.ftp_ok || 0);

            updateProgress(
                stats.processed,
                Number(data.total || 0)
            );

            $('processedStat').textContent = stats.processed;
            $('consoleOkStat').textContent = stats.ok;
            $('consoleFailedStat').textContent = stats.failed;
            $('ftpStat').textContent = stats.ftp;
            $('okStat').textContent = stats.ok;
            $('failedStat').textContent = stats.failed;
            addLog(data.message || 'Success', 'ok');
            break;

        case 'error':
            stats.processed = Number(data.current || 0);
            stats.ok = Number(data.success || 0);
            stats.failed = Number(data.failed || 0);
            stats.ftp = Number(data.ftp_ok || 0);

            updateProgress(
                stats.processed,
                Number(data.total || 0)
            );

            $('processedStat').textContent = stats.processed;
            $('consoleOkStat').textContent = stats.ok;
            $('consoleFailedStat').textContent = stats.failed;
            $('ftpStat').textContent = stats.ftp;
            $('okStat').textContent = stats.ok;
            $('failedStat').textContent = stats.failed;

            addLog('✕ ' + (data.name || '') + ' → ' + (data.error || 'Unknown Error') + (data.http_code ? ' [HTTP ' + data.http_code + ']' : ''),'error');
            break;

        case 'fatal':
            addLog('Fatal → ' + data.message, 'error');

            toast(
			data.message,
                true
            );
            break;

        case 'done':
            stats.processed = Number(data.total || 0);
            stats.ok = Number(data.success || 0);
            stats.failed = Number(data.failed || 0);
            stats.ftp = Number(data.ftp_ok || 0);

            $('processedStat').textContent = stats.processed;
            $('consoleOkStat').textContent = stats.ok;
            $('consoleFailedStat').textContent = stats.failed;
            $('ftpStat').textContent = stats.ftp;
            $('okStat').textContent = stats.ok;
            $('failedStat').textContent = stats.failed;
            $('progressBar').style.width = '100%';
            $('progressPercent').textContent = '100%';
            $('progressText').textContent = 'Completed';

            addLog('════════════════════════════════', 'ok');
            addLog('Done → ' + data.message, 'ok');
            toast('Generator Completed');
            break;
    }
}

async function startGenerator() {

    if (running) {
        return;
    }

    const categories = selectedCategories();

    if (!categories.length) {
        toast('Select at Least one Category',true);
        return;
    }

    resetStats();

    $('console').innerHTML = '';
    addLog('TRC4 Generator Starting...','info');
    addLog('Selected Categories: ' + categories.length, 'info');
    const uploadFTP = $('uploadFTP').checked;
    const clearPicons = $('clearPicons').checked;

    controller = new AbortController();

    setRunning(true);
    try {
        const response =
            await fetch(
                '?ajax=generate',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/json',
                        'Accept':
                            'application/x-ndjson'
                    },

                    body: JSON.stringify({
                        categories,
                        uploadFTP,
                        clearPicons
                    }),

                    signal:
                        controller.signal
                }
            );

        if (!response.ok) {
            throw new Error(
                'HTTP ' + response.status
            );
        }

        if (!response.body) {
            throw new Error(
                'Streaming Response is not Supported.'
            );
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (true) {
            const {
                value,
                done
            } = await reader.read();

            if (done) {
                break;
            }

            buffer +=
                decoder.decode(
                    value,
                    {
                        stream: true
                    }
                );

            const lines = buffer.split('\n');

            buffer = lines.pop() || '';

            for (const line of lines) {
                const trimmed = line.trim();

                if (!trimmed) {
                    continue;
                }

                try {
                    const data = JSON.parse(trimmed);
                    handleEvent(data);

                } catch (error) {

                    addLog('Invalid Server Event.','error');
                }
            }
        }

        if (buffer.trim()) {

            try {
                handleEvent(
                    JSON.parse(
                        buffer.trim()
                    )
                );

            } catch (error) {
                // Ignore incomplete final line.
            }
        }

    } catch (error) {

        if (
            error.name ===
            'AbortError'
        ) {

            addLog('■ Generator Stopped by User','error');
            toast('Generator Stopped.');

        } else {
            addLog('Request Error: ' +error.message,'error');

            toast(
                error.message,
                true
            );
        }

    } finally {

        controller = null;
        setRunning(false);
    }
}

function stopGenerator() {
    if (!controller) {
        return;
    }
    controller.abort();
}
updateSelected();
</script>
</body>
</html>