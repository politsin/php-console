<?php

namespace App\Command;

use App\Util\ExecTrait;
use Predis\Client as PredisClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Listen Ublox NMEA stream and print summary.
 */
#[AsCommand(name: 'gnss:listen', description: 'Read NMEA from Ublox and print live summary.')]
class GnssListenCommand extends Command {

  use ExecTrait;

  // phpcs:disable
  private SymfonyStyle $io;
  private string $port;
  private int $baud;
  private array $gsvBuffer = [];
  private array $posBuffer = [];
  private int $posWindowSec = 300;
  private ?PredisClient $redis = NULL;
  private int $redisTtl = 15;
  private array $lastSnr = ['min' => 0, 'avg' => 0.0, 'max' => 0, 'sv' => 0];
  private ?HttpClient $http = NULL;
  private ?HttpClient $httpTg = NULL;
  private ?HttpClient $httpHook = NULL;
  private array $lastSvIds = [];
  private float $lastRmcSpeedKn = -1.0;
  private array $alertTsByKey = [];
  private array $lastSatellites = [];
  private int $lastSvQuarterMinute = -1;
  private array $lastGsa = [];
  private array $lastGst = [];
  // phpcs:enable

  /**
   * Config.
   */
  protected function configure() {
    $this
      ->addOption('port', NULL, InputOption::VALUE_REQUIRED, 'Serial port', $_ENV['GNSS_PORT'] ?? '/dev/ttyACM0')
      ->addOption('baud', NULL, InputOption::VALUE_REQUIRED, 'Baud rate', (int) ($_ENV['GNSS_BAUD'] ?? 9600))
      ->addOption('test-telegram', NULL, InputOption::VALUE_NONE, 'Send test notification to Telegram and exit');
  }

  /**
   * Exec.
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $this->io = $io;
    $this->port = (string) $input->getOption('port');
    $this->baud = (int) $input->getOption('baud');

    if ($input->getOption('test-telegram')) {
      $this->notifyTelegram('GNSS test: OK', 'test', 0);
      $io->success('Telegram test attempted (see logs).');
      return Command::SUCCESS;
    }

    $io->section('GNSS Listen');
    $io->text("Port: {$this->port}, Baud: {$this->baud}");
    $this->configureSerial($this->port, $this->baud);

    $this->initRedis();
    $this->initInflux();
    $this->initWebhook();

    $fh = $this->openSerialStream($this->port);
    if ($fh === NULL) {
      $io->error('Failed to open serial port.');
      return Command::FAILURE;
    }
    stream_set_blocking($fh, FALSE);

    $buffer = '';
    while (TRUE) {
      $chunk = fgets($fh) ?: '';
      if ($chunk !== '') {
        $buffer .= $chunk;
        while (($pos = strpos($buffer, "\n")) !== FALSE) {
          $line = trim(substr($buffer, 0, $pos));
          $buffer = substr($buffer, $pos + 1);
          if ($line) {
            $this->handleLine($line);
          }
        }
      }
      usleep(50 * 1000);
    }
  }

  /**
   * Configure serial line with stty.
   */
  private function configureSerial(string $port, int $baud): void {
    try {
      $this->exec([
        '/bin/stty',
        '-F',
        $port,
        (string) $baud,
        'cs8',
        '-cstopb',
        '-parenb',
        '-echo',
        '-icanon',
        'min',
        '0',
        'time',
        '1',
      ]);
    }
    catch (\Throwable $e) {
      $this->io->warning('stty not applied: ' . $e->getMessage());
    }
  }

  /**
   * Open serial stream, prefer dio if present.
   */
  private function openSerialStream(string $port) {
    if (extension_loaded('dio')) {
      // Prefer stream for line reading; dio_open is available but unused.
    }
    $fh = @fopen($port, 'r+');
    if ($fh === FALSE) {
      return NULL;
    }
    return $fh;
  }

  /**
   * Handle single NMEA line.
   */
  private function handleLine(string $line): void {
    if ($line[0] !== '$' || strpos($line, '*') === FALSE) {
      return;
    }
    if (!$this->crcCheck($line)) {
      return;
    }
    $payload = substr($line, 1, strpos($line, '*') - 1);
    $parts = explode(',', $payload);
    $type = array_shift($parts);
    $talker = substr($type, 0, 2);
    $msg = substr($type, 2);
    switch ($msg) {
      case 'GGA':
        $this->parseGga($parts);
        break;

      case 'RMC':
        $this->parseRmc($parts);
        break;

      case 'GSV':
        $this->parseGsv($parts);
        break;

      case 'GSA':
        $this->parseGsa($parts);
        break;

      case 'GST':
        $this->parseGst($parts);
        break;

      default:
        // Skip other sentences for MVP.
        break;
    }
  }

  /**
   * CRC exclusive OR of all characters between '$' and '*'.
   */
  private function crcCheck(string $line): bool {
    $checksum = substr($line, strpos($line, '*') + 1);
    $start = strpos($line, '$') + 1;
    $finish = strpos($line, '*') - 1;
    $payload = substr($line, $start, $finish - $start + 1);
    $r = 0;
    foreach (str_split($payload) as $char) {
      $r = $r ^ ord($char);
    }
    $check = strtoupper(str_pad(dechex($r), 2, '0', STR_PAD_LEFT));
    if ($check !== strtoupper($checksum)) {
      $this->io->warning("CRC mismatch: calc=$check, got=$checksum, line=$payload");
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Parse GGA | Global positioning system fix data.
   */
  private function parseGga(array $d): void {
    // Indices follow standard GGA.
    $time = $d[0] ?? '';
    $lat = $this->toDecimalLatLon($d[1] ?? '', $d[2] ?? '');
    $lon = $this->toDecimalLatLon($d[3] ?? '', $d[4] ?? '');
    $fix = (int) ($d[5] ?? 0);
    $nsat = (int) ($d[6] ?? 0);
    $hdop = (float) ($d[7] ?? 0);
    $alt = (float) ($d[8] ?? 0);
    if ($fix > 0) {
      $this->addPositionSample($lat, $lon);
    }
    $drift = $this->computeDriftStats();
    $this->io->text(sprintf(
      'GGA t=%s fix=%d sv=%d hdop=%.1f alt=%.1f',
      $time,
      $fix,
      $nsat,
      $hdop,
      $alt,
    ));
    if ($fix > 0) {
      $this->io->text(sprintf(
        'POS lat=%.6f lon=%.6f | DRIFT r=%dm avg=%dm max=%dm n=%d',
        $lat,
        $lon,
        (int) round($drift['radius']),
        (int) round($drift['avg']),
        (int) round($drift['max']),
        $drift['count'],
      ));
    }
    $this->publishState([
      'type' => 'GGA',
      'time' => $time,
      'fix' => $fix,
      'sv' => $nsat,
      'hdop' => $hdop,
      'alt' => $alt,
      // Publish coordinates only when fix is valid.
      'lat' => $fix > 0 ? $lat : NULL,
      'lon' => $fix > 0 ? $lon : NULL,
      'drift' => $drift,
      'snr' => $this->lastSnr,
      'gsa' => $this->lastGsa,
      'gst' => $this->lastGst,
    ]);
  }

  /**
   * Parse RMC | Recommended Minimum data.
   */
  private function parseRmc(array $d): void {
    $time = $d[0] ?? '';
    $status = $d[1] ?? '';
    $lat = $this->toDecimalLatLon($d[2] ?? '', $d[3] ?? '');
    $lon = $this->toDecimalLatLon($d[4] ?? '', $d[5] ?? '');
    $speedKn = (float) ($d[6] ?? 0);
    $course = (float) ($d[7] ?? 0);
    $date = $d[8] ?? '';
    $this->lastRmcSpeedKn = $speedKn;
    if ($status === 'A') {
      $this->addPositionSample($lat, $lon);
    }
    $drift = $this->computeDriftStats();
    $this->io->text(sprintf(
      'RMC t=%s s=%s spd=%.1fkn crs=%.0f',
      $time,
      $status,
      $speedKn,
      $course,
    ));
    if ($status === 'A') {
      $this->io->text(sprintf(
        'POS lat=%.6f lon=%.6f | DRIFT r=%dm avg=%dm max=%dm n=%d',
        $lat,
        $lon,
        (int) round($drift['radius']),
        (int) round($drift['avg']),
        (int) round($drift['max']),
        $drift['count'],
      ));
    }
    $this->publishState([
      'type' => 'RMC',
      'time' => $time,
      'status' => $status,
      'speedKn' => $speedKn,
      'course' => $course,
      'date' => $date,
      'lat' => $status === 'A' ? $lat : NULL,
      'lon' => $status === 'A' ? $lon : NULL,
      'drift' => $drift,
      'snr' => $this->lastSnr,
      'gsa' => $this->lastGsa,
      'gst' => $this->lastGst,
    ]);
  }

  /**
   * Parse GSV | Satellites in View.
   */
  private function parseGsv(array $d): void {
    $total = (int) array_shift($d);
    $idx = (int) array_shift($d);
    $numSv = (int) array_shift($d);
    $this->gsvBuffer[$idx] = $d;
    if (count($this->gsvBuffer) === $total) {
      $flat = [];
      ksort($this->gsvBuffer);
      foreach ($this->gsvBuffer as $chunk) {
        $flat = [...$flat, ...$chunk];
      }
      $this->gsvBuffer = [];
      if (count($flat) % 4 === 0) {
        $sv = array_chunk($flat, 4);
        $snr = [];
        $ids = [];
        $satellites = [];
        foreach ($sv as $s) {
          $ids[] = isset($s[0]) ? (int) $s[0] : NULL;
          $sn = isset($s[3]) && $s[3] !== '' ? (int) $s[3] : NULL;
          if ($sn !== NULL) {
            $snr[] = $sn;
          }
          $satellites[] = [
            'id' => isset($s[0]) && $s[0] !== '' ? (int) $s[0] : NULL,
            'elev' => isset($s[1]) && $s[1] !== '' ? (int) $s[1] : NULL,
            'az' => isset($s[2]) && $s[2] !== '' ? (int) $s[2] : NULL,
            'snr' => isset($s[3]) && $s[3] !== '' ? (int) $s[3] : NULL,
          ];
        }
        $ids = array_values(array_filter($ids, function ($v) {
          return $v !== NULL;
        }));
        $min = $snr ? min($snr) : 0;
        $max = $snr ? max($snr) : 0;
        $avg = $snr ? array_sum($snr) / max(count($snr), 1) : 0;
        $this->lastSnr = [
          'min' => (int) $min,
          'avg' => $avg,
          'max' => (int) $max,
          'sv' => $numSv,
        ];
        $this->lastSatellites = $satellites;
        $this->detectSvSetChange($ids);
        $this->io->text(sprintf(
          'GSV sv_in_view=%d snr[min/avg/max]=%d/%.1f/%d',
          $numSv,
          $min,
          $avg,
          $max,
        ));
        $this->publishSatellites($satellites, $numSv, $this->lastSnr);
      }
      else {
        $this->io->warning('GSV data size mismatch.');
      }
    }
  }

  /**
   * Parse GSA | GNSS DOP and Active Satellites.
   */
  private function parseGsa(array $d): void {
    // NMEA GSA fields:
    // 0: Mode (M/A), 1: Fix type (1/2/3), 2..13: up to 12 PRN used,
    // 14: PDOP, 15: HDOP, 16: VDOP, [17: System ID - optional].
    $mode = trim($d[0] ?? '');
    $fixType = (int) ($d[1] ?? 0);
    $used = [];
    for ($i = 2; $i <= 13; $i++) {
      $prn = trim($d[$i] ?? '');
      if ($prn !== '') {
        $used[] = (int) $prn;
      }
    }
    $pdop = (float) ($d[14] ?? 0);
    $hdop = (float) ($d[15] ?? 0);
    $vdop = (float) ($d[16] ?? 0);
    $this->lastGsa = [
      'mode' => $mode,
      'fixType' => $fixType,
      'used' => $used,
      'pdop' => $pdop,
      'hdop' => $hdop,
      'vdop' => $vdop,
    ];
    $this->io->text(sprintf(
      'GSA used=%d PDOP=%.1f HDOP=%.1f VDOP=%.1f',
      count($used),
      $pdop,
      $hdop,
      $vdop,
    ));
  }

  /**
   * Parse GST | GNSS Pseudorange Error Statistics.
   */
  private function parseGst(array $d): void {
    // NMEA GST fields:
    // 0: UTC time, 1: RMS, 2: sigma_major, 3: sigma_minor, 4: orientation,
    // 5: sigma_lat, 6: sigma_lon, 7: sigma_alt.
    $time = trim($d[0] ?? '');
    $rms = (float) ($d[1] ?? 0);
    $sigmaLat = (float) ($d[5] ?? 0);
    $sigmaLon = (float) ($d[6] ?? 0);
    $sigmaAlt = (float) ($d[7] ?? 0);
    $this->lastGst = [
      'time' => $time,
      'rms' => $rms,
      'sigma_lat' => $sigmaLat,
      'sigma_lon' => $sigmaLon,
      'sigma_alt' => $sigmaAlt,
    ];
    $this->io->text(sprintf(
      'GST RMS=%.2f σlat=%.2f σlon=%.2f σalt=%.2f',
      $rms,
      $sigmaLat,
      $sigmaLon,
      $sigmaAlt,
    ));
  }

  /**
   * Convert NMEA lat/lon to decimal degrees.
   */
  private function toDecimalLatLon(string $value, string $hemisphere): float {
    if ($value === '' || $hemisphere === '') {
      return 0.0;
    }
    $num = (float) $value;
    $deg = floor($num / 100);
    $min = $num - ($deg * 100);
    $dec = $deg + ($min / 60.0);
    if ($hemisphere === 'S' || $hemisphere === 'W') {
      $dec = -$dec;
    }
    return $dec;
  }

  /**
   * Add position sample with monotonic time.
   */
  private function addPositionSample(float $lat, float $lon): void {
    $now = (int) floor(microtime(TRUE));
    $this->posBuffer[] = [
      't' => $now,
      'lat' => $lat,
      'lon' => $lon,
    ];
    $limit = $now - $this->posWindowSec;
    $this->posBuffer = array_values(array_filter($this->posBuffer, function ($s) use ($limit) {
      return ($s['t'] >= $limit);
    }));
  }

  /**
   * Compute drift statistics over window.
   */
  private function computeDriftStats(): array {
    $count = count($this->posBuffer);
    if ($count === 0) {
      return [
        'radius' => 0.0,
        'avg' => 0.0,
        'max' => 0.0,
        'count' => 0,
      ];
    }
    $sumLat = 0.0;
    $sumLon = 0.0;
    foreach ($this->posBuffer as $s) {
      $sumLat += $s['lat'];
      $sumLon += $s['lon'];
    }
    $centerLat = $sumLat / $count;
    $centerLon = $sumLon / $count;
    $distances = [];
    foreach ($this->posBuffer as $s) {
      $distances[] = $this->distanceMeters(
        $centerLat,
        $centerLon,
        $s['lat'],
        $s['lon']
      );
    }
    $avg = $distances ? array_sum($distances) / max(count($distances), 1) : 0.0;
    $max = $distances ? max($distances) : 0.0;
    $radius = $max;
    return [
      'radius' => $radius,
      'avg' => $avg,
      'max' => $max,
      'count' => $count,
    ];
  }

  /**
   * Haversine distance in meters.
   */
  private function distanceMeters(
    float $lat1,
    float $lon1,
    float $lat2,
    float $lon2,
  ): float {
    $r = 6371000.0;
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lon2 - $lon1);
    $a = sin($dphi / 2) * sin($dphi / 2)
      + cos($phi1) * cos($phi2) * sin($dl / 2) * sin($dl / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $r * $c;
  }

  /**
   * Init Redis client from env.
   */
  private function initRedis(): void {
    $dsn = (string) ($_ENV['REDIS_DSN'] ?? '');
    $ttl = (int) ($_ENV['GNSS_REDIS_TTL'] ?? 15);
    if ($dsn === '') {
      $host = (string) ($_ENV['REDIS_HOST'] ?? '127.0.0.1');
      $port = (string) ($_ENV['REDIS_PORT'] ?? '6379');
      $user = (string) ($_ENV['REDIS_USER'] ?? '');
      $pass = (string) ($_ENV['REDIS_PASS'] ?? '');
      $auth = '';
      if ($pass !== '') {
        // Use password-only auth (no ACL user) unless REDIS_USER provided.
        $auth = ($user !== '') ? ($user . ':' . $pass . '@') : (':' . $pass . '@');
      }
      $dsn = 'redis://' . $auth . $host . ':' . $port;
    }
    $this->redisTtl = max($ttl, 1);
    try {
      $this->redis = new PredisClient($dsn);
      $this->redis->connect();
      $this->io->text('Redis: connected');
    }
    catch (\Throwable $e) {
      $this->redis = NULL;
      $this->io->warning('Redis disabled: ' . $e->getMessage());
    }
  }

  /**
   * Publish latest GNSS state to Redis with TTL.
   */
  private function publishState(array $state): void {
    if ($this->redis === NULL) {
      return;
    }
    $hasFix = FALSE;
    if (isset($state['fix'])) {
      $hasFix = ((int) $state['fix']) > 0;
    }
    elseif (isset($state['status'])) {
      $hasFix = ($state['status'] === 'A');
    }
    if (!$hasFix) {
      // No valid fix: always notify via webhook, skip Redis/Influx/history.
      $this->sendWebhook($state);
      return;
    }
    // Ensure event timestamp embedded into state.
    $eventTs = time();
    $state['event_ts'] = $state['event_ts'] ?? $eventTs;
    $state['event_iso'] = $state['event_iso'] ?? gmdate('c', (int) $state['event_ts']);
    // Attach diagnostic hints.
    $state['diag'] = $this->buildDiag($state);
    $payload = json_encode([
      'ts' => $eventTs,
      'port' => $this->port,
      'baud' => $this->baud,
      'state' => $state,
      'satellites' => $this->lastSatellites,
      'gsa' => $this->lastGsa,
      'gst' => $this->lastGst,
    ]);
    try {
      $this->redis->setex('gnss:state:latest', $this->redisTtl, $payload);
      // Minute-level history key, e.g. gnss:state:2025:08:27:12:34.
      $ts = (int) time();
      $minuteKey = 'gnss:state:' . date('Y:m:d:H:i', $ts);
      $historyTtl = (int) ($_ENV['GNSS_REDIS_HISTORY_TTL'] ?? 86400);
      $this->redis->setex($minuteKey, max($historyTtl, $this->redisTtl), $payload);
    }
    catch (\Throwable $e) {
      $this->io->warning('Redis publish failed: ' . $e->getMessage());
    }

    $this->influxWrite($state);
    $this->detectSpoofing($state);
    $this->sendWebhook($state);
  }

  /**
   * Init Influx HTTP client.
   */
  private function initInflux(): void {
    $url = trim((string) ($_ENV['INFLUX_URL'] ?? ''));
    $host = trim((string) ($_ENV['INFLUX_HOST'] ?? ''));
    $port = trim((string) ($_ENV['INFLUX_PORT'] ?? ''));
    if ($url === '' && $host !== '') {
      $schemeHost = preg_match('/^https?:\/\//', $host) ? $host : ('http://' . $host);
      $p = $port !== '' ? (':' . $port) : ':8086';
      $url = $schemeHost . $p;
    }
    if ($url === '') {
      $this->http = NULL;
      return;
    }
    $token = (string) ($_ENV['INFLUX_TOKEN'] ?? ($_ENV['INFLUX_TOK'] ?? ''));
    $this->http = new HttpClient([
      'base_uri' => rtrim($url, '/'),
      'timeout' => 2.0,
      'headers' => [
        'Authorization' => 'Token ' . $token,
        'Content-Type' => 'text/plain; charset=utf-8',
        'Accept' => 'application/json',
      ],
    ]);
    $this->io->text('Influx: configured');
  }

  /**
   * Write single point to Influx in line protocol.
   */
  private function influxWrite(array $state): void {
    if ($this->http === NULL) {
      return;
    }
    $orgRaw = (string) ($_ENV['INFLUX_ORG'] ?? ($_ENV['INFLUX_ORGANIZATION'] ?? ''));
    $bucketRaw = (string) ($_ENV['INFLUX_BUCKET'] ?? ($_ENV['INFLUX_BUCKET_NAME'] ?? ''));
    $org = urlencode($orgRaw);
    $bucket = urlencode($bucketRaw);
    if ($org === '' || $bucket === '') {
      return;
    }

    $tags = [
      'host' => gethostname() ?: 'unknown',
      'port' => $this->port,
    ];
    $fields = [
      'lat' => (float) ($state['lat'] ?? 0.0),
      'lon' => (float) ($state['lon'] ?? 0.0),
      'alt' => (float) ($state['alt'] ?? 0.0),
      'fix' => (int) ($state['fix'] ?? 0),
      'sv' => (int) ($state['sv'] ?? ($state['snr']['sv'] ?? 0)),
      'hdop' => (float) ($state['hdop'] ?? 0.0),
      'snr_min' => (int) ($state['snr']['min'] ?? 0),
      'snr_avg' => (float) ($state['snr']['avg'] ?? 0.0),
      'snr_max' => (int) ($state['snr']['max'] ?? 0),
      'drift_r' => (float) ($state['drift']['radius'] ?? 0.0),
      'drift_avg' => (float) ($state['drift']['avg'] ?? 0.0),
      'drift_max' => (float) ($state['drift']['max'] ?? 0.0),
      'pdop' => (float) ($state['gsa']['pdop'] ?? 0.0),
      'vdop' => (float) ($state['gsa']['vdop'] ?? 0.0),
      'gst_rms' => (float) ($state['gst']['rms'] ?? 0.0),
    ];
    // Optionally emit per-satellite SNR as fields (id-indexed) once per quarter minute.
    $quarter = (int) floor(time() / 900);
    if ($this->lastSvQuarterMinute !== $quarter && !empty($this->lastSatellites)) {
      $this->lastSvQuarterMinute = $quarter;
      foreach ($this->lastSatellites as $sat) {
        if (!empty($sat['id']) && isset($sat['snr'])) {
          $fields['snr_sat_' . (int) $sat['id']] = (int) $sat['snr'];
        }
      }
    }
    $measurement = 'gnss';

    $tagStr = [];
    foreach ($tags as $k => $v) {
      $tagStr[] = $this->influxEscape((string) $k) . '=' . $this->influxEscape((string) $v);
    }

    $fieldStr = [];
    foreach ($fields as $k => $v) {
      if (is_int($v)) {
        $fieldStr[] = $this->influxEscape((string) $k) . '=' . $v . 'i';
      }
      elseif (is_float($v)) {
        // Increase precision for lat/lon and numeric fields to 9 decimals.
        $fieldStr[] = $this->influxEscape((string) $k) . '=' . rtrim(rtrim(number_format($v, 9, '.', ''), '0'), '.');
      }
    }
    $line = $this->influxEscape($measurement)
      . ($tagStr ? ',' . implode(',', $tagStr) : '')
      . ' '
      . implode(',', $fieldStr)
      . ' '
      . (int) (microtime(TRUE) * 1_000_000_000);

    try {
      $this->http->post("/api/v2/write?org={$org}&bucket={$bucket}&precision=ns", [
        'body' => $line,
      ]);
    }
    catch (\Throwable $e) {
      $this->io->warning('Influx write failed: ' . $e->getMessage());
    }
  }

  /**
   * Escape for Influx line protocol.
   */
  private function influxEscape(string $s): string {
    return str_replace([',', ' ', '='], ['\\,', '\\ ', '\\='], $s);
  }

  /**
   * Build diagnostic hints.
   */
  private function buildDiag(array $state): array {
    $diag = [];
    $svInView = (int) ($this->lastSnr['sv'] ?? 0);
    $used = isset($this->lastGsa['used']) ? (int) count($this->lastGsa['used']) : 0;
    $pdop = (float) ($this->lastGsa['pdop'] ?? 0.0);
    $snrAvg = (float) ($this->lastSnr['avg'] ?? 0.0);
    $rms = (float) ($this->lastGst['rms'] ?? 0.0);
    // Heuristic: many visible, few used, decent SNR -> geometry/filters issue.
    if ($svInView >= 8 && $used <= 3 && $snrAvg >= 20.0) {
      $diag[] = 'vis_but_not_used';
    }
    if ($pdop >= 4.0) {
      $diag[] = 'pdop_high';
    }
    if ($rms >= 10.0) {
      $diag[] = 'gst_rms_high';
    }
    if (empty($diag)) {
      $diag[] = 'ok';
    }
    return [
      'tags' => $diag,
      'sv_in_view' => $svInView,
      'sv_used' => $used,
      'pdop' => $pdop,
      'snr_avg' => $snrAvg,
      'gst_rms' => $rms,
    ];
  }

  /**
   * Init webhook HTTP client.
   */
  private function initWebhook(): void {
    $url = trim((string) ($_ENV['WEBHOOK_URL'] ?? ''));
    if ($url === '') {
      $this->httpHook = NULL;
      return;
    }
    $this->httpHook = new HttpClient(['timeout' => 3.0]);
  }

  /**
   * Send JSON snapshot to webhook.
   */
  private function sendWebhook(array $state): void {
    if ($this->httpHook === NULL) {
      return;
    }
    $url = (string) $_ENV['WEBHOOK_URL'];
    if ($url === '') {
      return;
    }
    $base = rtrim($url, '/');
    if (str_ends_with($base, '/gps')) {
      $base = substr($base, 0, -4);
    }
    if (!str_ends_with($base, '/gnss')) {
      $base = $base . '/gnss';
    }
    $eventTs = time();
    // Ensure event timestamp embedded into state for webhook too.
    $state['event_ts'] = $state['event_ts'] ?? $eventTs;
    $state['event_iso'] = $state['event_iso'] ?? gmdate('c', (int) $state['event_ts']);
    // Attach diagnostic hints for webhook.
    $state['diag'] = $state['diag'] ?? $this->buildDiag($state);
    $payload = [
      'ts' => $eventTs,
      'host' => gethostname() ?: 'unknown',
      'port' => $this->port,
      'baud' => $this->baud,
      'snr' => $this->lastSnr,
      'state' => $state,
      'satellites' => $this->lastSatellites,
    ];
    try {
      $this->httpHook->post($base, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode($payload),
      ]);
    }
    catch (\Throwable $e) {
      $status = NULL;
      $respBody = NULL;
      if ($e instanceof RequestException && $e->hasResponse()) {
        try {
          $status = $e->getResponse()->getStatusCode();
          $respBody = (string) $e->getResponse()->getBody();
        }
        catch (\Throwable $ignored) {
        }
      }
      $err = [
        'ts' => time(),
        'url' => $base,
        'status' => $status,
        'message' => $e->getMessage(),
      ];
      if ($respBody !== NULL) {
        $err['body'] = mb_substr($respBody, 0, 500);
      }
      $msg = 'Webhook send failed: ' . json_encode($err);
      $this->io->error($msg);
      @error_log('[gnss] ' . $msg);
      if ($this->redis !== NULL) {
        try {
          $this->redis->setex('gnss:webhook:last_error', 300, json_encode($err));
          $this->redis->incr('gnss:webhook:error_count');
        }
        catch (\Throwable $ignored) {
        }
      }
      // By default fail fast if webhook did not succeed.
      throw new \RuntimeException($msg);
    }
  }

  /**
   * Detect spoofing events based on jumps and drift.
   */
  private function detectSpoofing(array $state): void {
    $jumpM = (float) ($_ENV['SPOOF_JUMP_M'] ?? 100);
    $driftRm = (float) ($_ENV['SPOOF_DRIFT_R_M'] ?? 50);
    $minInterval = (int) ($_ENV['SPOOF_MIN_ALERT_INTERVAL'] ?? 60);

    // Position jump with low speed.
    $n = count($this->posBuffer);
    if ($n >= 2) {
      $a = $this->posBuffer[$n - 2];
      $b = $this->posBuffer[$n - 1];
      $dist = $this->distanceMeters($a['lat'], $a['lon'], $b['lat'], $b['lon']);
      $spdKn = $this->lastRmcSpeedKn;
      if ($dist >= $jumpM && ($spdKn >= 0 && $spdKn < 1.0)) {
        $this->notifyTelegram('GNSS: резкий скачок координат ' . (int) $dist . ' м при низкой скорости.', 'pos_jump', $minInterval);
      }
    }

    // Large drift radius.
    if (!empty($state['drift']['radius']) && $state['drift']['radius'] >= $driftRm) {
      $this->notifyTelegram('GNSS: повышенный дрейф, радиус ~' . (int) $state['drift']['radius'] . ' м.', 'drift_high', $minInterval);
    }
  }

  /**
   * Detect sudden satellite set replacement.
   */
  private function detectSvSetChange(array $currentIds): void {
    $prev = $this->lastSvIds;
    $this->lastSvIds = $currentIds;
    if (empty($prev)) {
      return;
    }
    $minInterval = (int) ($_ENV['SPOOF_MIN_ALERT_INTERVAL'] ?? 60);
    $svJac = (float) ($_ENV['SPOOF_SV_JACCARD'] ?? 0.3);
    if (count($prev) < 5 || count($currentIds) < 5) {
      return;
    }
    $intersect = array_values(array_intersect($prev, $currentIds));
    $union = array_values(array_unique([...$prev, ...$currentIds]));
    $j = count($union) ? (count($intersect) / count($union)) : 1.0;
    if ($j < $svJac) {
      $this->notifyTelegram('GNSS: резкая смена набора спутников (Jaccard=' . number_format($j, 2) . ').', 'sv_change', $minInterval);
    }
  }

  /**
   * Telegram notify with simple rate limiting.
   */
  private function notifyTelegram(string $text, string $key, int $minInterval): void {
    $token = (string) (
      $_ENV['TELEGRAM_BOT_TOKEN']
      ?? $_ENV['TELEGRAM_TOKEN']
      ?? $_ENV['TG_BOT_TOKEN']
      ?? $_ENV['TG_KEY']
      ?? $_ENV['TG_NAME']
      ?? ''
    );
    $chat = (string) ($_ENV['TELEGRAM_CHAT_ID'] ?? ($_ENV['TELEGRAM_CHAT'] ?? ($_ENV['TG_CHAT_ID'] ?? ($_ENV['TG_CHAT'] ?? ''))));
    if ($token === '' || $chat === '') {
      return;
    }
    $now = time();
    $last = (int) ($this->alertTsByKey[$key] ?? 0);
    if (($now - $last) < $minInterval) {
      return;
    }
    $this->alertTsByKey[$key] = $now;
    if ($this->httpTg === NULL) {
      $this->httpTg = new HttpClient(['timeout' => 3.0]);
    }
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $this->io->text('Telegram: notify ' . $key);
    try {
      $this->httpTg->post($url, [
        'form_params' => [
          'chat_id' => $chat,
          'text' => $text,
          'disable_notification' => FALSE,
        ],
      ]);
    }
    catch (\Throwable $e) {
      $this->io->warning('Telegram notify failed: ' . $e->getMessage());
    }
  }

  /**
   * Publish satellites snapshot every 15 minutes and keep in latest.
   */
  private function publishSatellites(array $satellites, int $numSv, array $snr): void {
    if ($this->redis === NULL) {
      return;
    }
    $ts = (int) time();
    $quarter = (int) floor($ts / 900);
    $payload = json_encode([
      'ts' => $ts,
      'numSv' => $numSv,
      'snr' => $snr,
      'satellites' => $satellites,
    ]);
    try {
      $this->redis->setex('gnss:satellites:latest', max($this->redisTtl, 60), $payload);
      if ($this->lastSvQuarterMinute !== $quarter) {
        $this->lastSvQuarterMinute = $quarter;
        $key = 'gnss:satellites:' . date('Y:m:d:H:i', $ts);
        $ttl = (int) ($_ENV['GNSS_REDIS_SV_TTL'] ?? 7 * 24 * 3600);
        $this->redis->setex($key, max($ttl, $this->redisTtl), $payload);
      }
    }
    catch (\Throwable $e) {
      $this->io->warning('Redis satellites publish failed: ' . $e->getMessage());
    }
  }

}
