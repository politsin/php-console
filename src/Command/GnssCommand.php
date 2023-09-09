<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Fawno\PhpSerial\Config\BaudRates;
use Fawno\PhpSerial\Config\DataBits;
use Fawno\PhpSerial\Config\Parity;
use Fawno\PhpSerial\Config\StopBits;
use Fawno\PhpSerial\SerialConfig;
use Fawno\PhpSerial\SerialDio;

/**
 * GNSS.
 */
class GnssCommand extends Command {

  // phpcs:disable
  private SerialDio $serial;
  private SymfonyStyle $io;
  private string $port = '/dev/ttyACM0';
  private int $count = 0;
  // phpcs:enable

  /**
   * Config.
   */
  protected function configure() {
    $this->setName('gnss')
      ->setDescription('NEO-7 u-blox GNSS modules');
  }

  /**
   * Exec.
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = new SymfonyStyle($input, $output);
    $io->section('GNSS');
    $this->io = $io;
    $this->runTty($io);
    return 0;
  }

  /**
   * Command and wait ok.
   */
  protected function runTty($io) {
    $this->resetSerial();
    $this->initSerial();
    $this->runSerial();
  }

  /**
   * Serial init.
   */
  protected function resetSerial() : void {
    // shell_exec('usbreset 1546:01a7');
    // shell_exec('usbreset 1a86:7523');.
  }

  /**
   * Serial init.
   */
  protected function initSerial() {
    $config = $this->getConfig();
    $serial = new SerialDio($this->port, $config);
    $serial->open('r+b');
    $serial->setBlocking(0);
    $serial->setTimeout(0, 0);
    $this->serial = $serial;
  }

  /**
   * Serial init.
   */
  protected function gotMessage(string $msg) {
    $data = explode(",", $msg);
    if (count($data) > 2) {
      $type = trim(array_shift($data));
      array_pop($data);
      switch (substr($type, 3)) {
        case 'RMC':
          // Recommended Minimum data.
          $this->parseRmc($data);
          break;

        case 'GLL':
          // $this->parseGll($data);
          break;

        default:
          // $this->io->text("$type " . implode(",", $data));
          break;
      }
    }
    else {
      $this->io->warning("SMALL DATA: " . implode(",", $data));
    }
  }

  /**
   * CRC exclusive OR of all characters between '$' and '*'.
   */
  protected function crcCheck(string $line) : bool {
    $result = FALSE;
    $checksum = substr($line, strpos($line, '*') + 1);
    $start = strpos($line, '$') + 1;
    $finish = strpos($line, '*') - 1;
    $line = substr($line, $start, $finish);
    $r = 0;
    foreach (str_split($line) as $char) {
      $r = $r ^ ord($char);
    }
    $ch = strtoupper(gmp_strval($r, 16));
    $check = str_pad($ch, 2, "0", STR_PAD_LEFT);
    if ($check == $checksum) {
      $result = TRUE;
    }
    else {
      $this->io->error("$check | $checksum | >$line<");
    }
    return $result;
  }

  /**
   * Parse GLL.
   */
  protected function parseDtm(array $data) : void {
    $this->io->text(json_encode($data));
  }

  /**
   * Parse GLL.
   */
  protected function parseGll(array $data) : void {
    $coord = [];
    $i = $this->count++;
    $lat = floatval(trim($data[0])) / 100;
    $lon = floatval(trim($data[2])) / 100;
    $alt = floatval(trim($data[4])) / 1000;
    $coord = [
      'lat' => number_format($lat, 9, '.'),
      'lon' => number_format($lon, 9, '.'),
      'alt' => number_format($alt, 3, '.'),
    ];
    $this->io->text("$i\t[{$coord['lat']},{$coord['lon']}]\t{$coord['alt']}");
    $this->io->text(json_encode($data));
  }

  /**
   * Parse RMC | Recommended Minimum data.
   */
  protected function parseRmc(array $data) : array {
    $result = [
      'status' => ($data[1] == 'A') ? 'Valid' : 'Warning',
      'lat' => $data[2],
      'long' => $data[4],
      'NS-EW' => "{$data[3]}{$data[5]}",
      'speed' => $data[6],
      'course' => $data[7],
      'date' => $data[8],
      'time' => $data[0],
    ];
    $this->io->text(json_encode($result));
    return $result;
  }

  /**
   * Parse VTG | Course over ground and Ground speed.
   */
  protected function parseVtg(array $data) : void {
  }

  /**
   * Parse GGA | Global positioning system fix data.
   */
  protected function parseGga(array $data) : void {
  }

  /**
   * Parse GSA | GNSS DOP and Active Satellites.
   */
  protected function parseGsa(array $data) : void {
  }

  /**
   * Parse GSV | GNSS Satellites in View.
   */
  protected function parseGsv(array $data) : void {
  }

  /**
   * Serial init.
   */
  protected function runSerial() {
    $serial = $this->serial;
    $ok = "";
    while ($ok != "ok") {
      usleep(100);
      $responce = $serial->read();
      foreach (explode("\n", $responce) as $line) {
        $data = trim($line);
        if ($data && $this->crcCheck($line)) {
          $this->gotMessage($data);
        }
      }
    }
  }

  /**
   * Get Config.
   */
  protected function getConfig() : SerialConfig {
    $config = new SerialConfig();
    $config->setBaudRate(BaudRates::B115200);
    $config->setDataBits(DataBits::CS8);
    $config->setStopBits(StopBits::ONE);
    $config->setParity(Parity::NONE);
    $config->setFlowControl(TRUE);
    // $config->setCanonical(FALSE);
    return $config;
  }

}
