<?php

namespace App\Command;

use App\Util\ExecTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Configure u-blox with a maximal/diagnostic configuration.
 */
#[AsCommand(name: 'gnss:cfg-max', description: 'Enable rich NMEA/UBX messages and set high-quality defaults.')]
class GnssCfgMaxCommand extends Command {

  use ExecTrait;

  // phpcs:disable
  private SymfonyStyle $io;
  private string $port;
  private int $baud;
  private $fh;
  // phpcs:enable

  /**
   * Config.
   */
  protected function configure() {
    $this
      ->addOption('port', NULL, InputOption::VALUE_REQUIRED, 'Serial port', $_ENV['GNSS_PORT'] ?? '/dev/ttyACM0')
      ->addOption('baud', NULL, InputOption::VALUE_REQUIRED, 'Baud rate', (int) ($_ENV['GNSS_BAUD'] ?? 9600));
  }

  /**
   * Exec.
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $this->io = $io;
    $this->port = (string) $input->getOption('port');
    $this->baud = (int) $input->getOption('baud');

    $io->section('Configure u-blox (max)');
    $io->text("Port: {$this->port}, Baud: {$this->baud}");
    $this->configureSerial($this->port, $this->baud);
    $this->fh = @fopen($this->port, 'r+');
    if ($this->fh === FALSE) {
      $io->error('Failed to open serial port.');
      return Command::FAILURE;
    }
    stream_set_blocking($this->fh, TRUE);
    stream_set_timeout($this->fh, 1);

    // Set navigation/measurement rate to 1 Hz (1000 ms, GPS time).
    $this->sendUbxCfgRate(1000, 1, 1);
    // Enable essential NMEA messages on UART1 and USB.
    $this->enableNmeaMessage(0x00);
    $this->enableNmeaMessage(0x02);
    $this->enableNmeaMessage(0x03);
    $this->enableNmeaMessage(0x04);
    $this->enableNmeaMessage(0x07);
    $this->enableNmeaMessage(0x08);
    // Enable UBX diagnostic/navigation messages NAV-PVT, NAV-SAT, NAV-DOP.
    $this->enableUbxMessage(0x01, 0x07);
    $this->enableUbxMessage(0x01, 0x35);
    $this->enableUbxMessage(0x01, 0x04);

    // Note: constellation and dynamic model are device-specific. Skipped here.
    $io->success('Configuration commands sent. Power-cycle may be required to take full effect.');
    return Command::SUCCESS;
  }

  /**
   * Apply stty to serial line.
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
   * Send UBX-CFG-RATE.
   */
  private function sendUbxCfgRate(int $measRateMs, int $navRate, int $timeRef): void {
    $payload = pack('vvv', $measRateMs, $navRate, $timeRef);
    $this->sendUbx(0x06, 0x08, $payload);
  }

  /**
   * Enable NMEA sentence on UART1 and USB: CFG-MSG for class 0xF0, id.
   */
  private function enableNmeaMessage(int $nmeaId): void {
    // Rates per port: I2C, UART1, UART2, USB, SPI, reserved.
    $rates = [0x00, 0x01, 0x00, 0x01, 0x00, 0x00];
    $payload = pack('CCCCCCCC', 0xF0, $nmeaId, ...$rates);
    $this->sendUbx(0x06, 0x01, $payload);
  }

  /**
   * Enable UBX NAV message on UART1 and USB: CFG-MSG for class/id.
   */
  private function enableUbxMessage(int $cls, int $id): void {
    $rates = [0x00, 0x01, 0x00, 0x01, 0x00, 0x00];
    $payload = pack('CCCCCCCC', $cls, $id, ...$rates);
    $this->sendUbx(0x06, 0x01, $payload);
  }

  /**
   * Send one UBX packet.
   */
  private function sendUbx(int $cls, int $id, string $payload): void {
    $len = strlen($payload);
    $hdr = pack('CC', 0xB5, 0x62) . pack('CC', $cls, $id) . pack('v', $len);
    [$ckA, $ckB] = $this->ubxChecksum(pack('CCv', $cls, $id, $len) . $payload);
    $pkt = $hdr . $payload . pack('CC', $ckA, $ckB);
    fwrite($this->fh, $pkt);
    fflush($this->fh);
    // Try to read ACK-ACK/NAK shortly (optional).
    $ack = $this->readBytes(16);
    if ($ack) {
      $this->io->text('UBX sent cls=' . dechex($cls) . ' id=' . dechex($id));
    }
  }

  /**
   * UBX checksum.
   */
  private function ubxChecksum(string $data): array {
    $ckA = 0;
    $ckB = 0;
    $bytes = array_values(unpack('C*', $data));
    foreach ($bytes as $b) {
      $ckA = ($ckA + $b) & 0xFF;
      $ckB = ($ckB + $ckA) & 0xFF;
    }
    return [$ckA, $ckB];
  }

  /**
   * Read few bytes non-blocking with small timeout.
   */
  private function readBytes(int $max): string {
    $res = '';
    $start = microtime(TRUE);
    while ((microtime(TRUE) - $start) < 0.1 && strlen($res) < $max) {
      $chunk = fread($this->fh, $max - strlen($res));
      if ($chunk !== FALSE && $chunk !== '') {
        $res .= $chunk;
      }
      usleep(5 * 1000);
    }
    return $res;
  }

}
