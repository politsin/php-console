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
  protected function resetSerial() {
    // GNSS: shell_exec('usbreset 1546:01a7').
    shell_exec('usbreset 1a86:7523');
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
    // $this->io->text($msg);
    $data = explode(",", $msg);
    $type = trim($data[0]);
    $coord = [];
    if ($type == '$GPGLL') {
      $lat = floatval(trim($data[1])) / 100;
      $lon = floatval(trim($data[3])) / 100;
      $alt = floatval(trim($data[5])) / 1000;
      $coord = [
        'lat' => number_format($lat, 9),
        'lon' => number_format($lon, 9),
        'alt' => number_format($alt, 3),
      ];
      $this->io->text(json_encode($coord));
    }
    else {
      // $this->io->text($type);
    }
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
        if ($data) {
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
