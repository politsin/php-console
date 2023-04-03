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
 * Marlin G-code Mixer.
 *
 * Find USB-tty (СH340 USB-Serial):
 * - `dmesg | grep tty`.
 * - `setserial -g /dev/ttyUSB[01]`
 * https://learn.sparkfun.com/tutorials/how-to-install-ch340-drivers/linux.
 */
class MixerCommand extends Command {

  // phpcs:disable
  private SerialDio $serial;
  private SymfonyStyle $io;
  // phpcs:enable

  /**
   * Config.
   */
  protected function configure() {
    $this
      ->setName('mixer')
      ->setDescription('php with marlin, oue!!');
  }

  /**
   * Exec.
   *
   * G91 - Relative Positioning
   * G90 - Absolute Positioning
   * M17 - On
   * M18 - Off.
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = new SymfonyStyle($input, $output);
    $io->section('Hello');
    $this->io = $io;
    $this->initSerial();
    $commands = [
      "G91",
      "G0 X10",
      "G0 Y20",
      "G0 Z30",
      "G0 E40",
      "G0 X10 Y10 Z10 E10",
      "G0 Y20",
      "G0 Z30",
      "M18",
    ];
    foreach ($commands as $cmd) {
      $this->stepAndOk($cmd);
    }
    return 0;
  }

  /**
   * Command and wait ok.
   */
  protected function stepAndOk(string $command) {
    $this->io->text($command);
    $this->serial->send("$command\r\n");
    $ok = "";
    while ($ok != "ok") {
      $responce = $this->serial->read();
      foreach (explode("\n", $responce) as $line) {
        $data = trim($line);
        if ($data) {
          $this->io->text($data);
          if ($data == "ok") {
            $ok = "ok";
          }
        }
      }
    }
    usleep(100 * 1000);
  }

  /**
   * Serial init.
   */
  protected function initSerial() {
    $config = $this->getConfig();
    $serial = new SerialDio('/dev/ttyUSB0', $config);
    $serial->open('r+b');
    $serial->setBlocking(0);
    $serial->setTimeout(0, 0);
    $ok = "";
    while ($ok != "ok") {
      $serial->send("M118 hello\r\n");
      usleep(10 * 1000);
      $responce = $serial->read();
      foreach (explode("\n", $responce) as $line) {
        $data = trim($line);
        $this->io->text($data);
        if ($data == "ok") {
          $ok = "ok";
        }
      }
    }
    $this->serial = $serial;
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
