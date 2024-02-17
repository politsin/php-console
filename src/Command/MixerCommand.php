<?php

namespace App\Command;

use Fawno\PhpSerial\Config\BaudRates;
use Fawno\PhpSerial\Config\DataBits;
use Fawno\PhpSerial\Config\Parity;
use Fawno\PhpSerial\Config\StopBits;
use Fawno\PhpSerial\SerialConfig;
use Fawno\PhpSerial\SerialDio;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Marlin G-code Mixer.
 *
 * Find USB-tty (СH340 USB-Serial):
 * - `dmesg | grep tty`.
 * - `setserial -g /dev/ttyUSB[01]`
 * - `usbreset 1a86:7523`
 * - setserial -g /dev/ttyACM0
 * https://learn.sparkfun.com/tutorials/how-to-install-ch340-drivers/linux.
 */
class MixerCommand extends Command {

  // phpcs:disable
  private SerialDio $serial;
  private SymfonyStyle $io;
  private string $port = '/dev/ttyACM0';
  // private string $port = '/dev/ttyACM0';
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
    // $io->section('Hello');
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
    $commands = [
      "G91",
      "G4 P100",
      "G0 X-35 F3000",
      "G4 S1",
      "G0 Y-54 F3000",
      "G4 S1",
      "G0 Z-37 F3000",
      "G4 S1",
      "G0 E-40 F3000",
      "G4 S1",
    // "G0 X-350 Y-540 Z-370 E-200 F6000",
      "G4 S1",
      "M18",
    ];
    $io->text("Commands:");
    foreach ($commands as $cmd) {
      $this->stepAndOk($cmd);
    }
  }

  /**
   * Command and wait ok.
   */
  protected function stepAndOk(string $command) {
    $this->io->text($command);
    $this->serial->send("$command\r\n");
    $ok = "";
    if (TRUE) {
      while ($ok != "ok") {
        usleep(100);
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
    }
    usleep(100 * 1000);
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
    $ok = "";
    while ($ok != "ok") {
      $serial->send("M118 hello\r\n");
      usleep(100);
      $responce = $serial->read();
      foreach (explode("\n", $responce) as $line) {
        $data = trim($line);
        if ($data) {
          $this->io->text($data);
        }
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
