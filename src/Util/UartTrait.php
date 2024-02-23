<?php

namespace App\Util;

use Fawno\PhpSerial\Config\BaudRates;
use Fawno\PhpSerial\Config\DataBits;
use Fawno\PhpSerial\Config\Parity;
use Fawno\PhpSerial\Config\StopBits;
use Fawno\PhpSerial\SerialConfig;
use Fawno\PhpSerial\SerialDio;

/**
 * Uart Trait.
 */
trait UartTrait {

  /**
   * Serial init.
   */
  protected function initSerial(string $port) : SerialDio {
    $config = $this->getConfig();
    $this->io->writeln("1 Serial: $port");
    $serial = new SerialDio($port, $config);
    $serial->open('r+b');
    $serial->setBlocking(0);
    $serial->setTimeout(0, 0);
    $serial->send("hello\r\n");
    if (FALSE) {
    }
    return $serial;
  }

  /**
   * Serial init.
   */
  protected function resetSerial($id = '214b:7250') {
    // GNSS: shell_exec('usbreset 1546:01a7').
    shell_exec("usbreset $id");
  }

  /**
   * Current data.
   */
  protected function usbList($list = FALSE) : array {
    if ($list) {
      $info = explode("\n", trim(shell_exec('/usr/bin/usb-devices')));
      $data = [];
      $k = 0;
      foreach ($info as $line) {
        if (!$line) {
          $k++;
        }
        else {
          $data[$k][] = $line;
        }
      }
      dump($data);
    }
    $map = [
      '1a86:7523' => 'Marlin',
      '214b:7250' => 'Esp32 Scales',
    ];
    dump($map);
    $usb = explode("\n", trim(shell_exec('/usr/bin/ls /dev/ttyUSB*')));
    return $usb;
  }

  /**
   * Driver test.
   */
  private function driverTest() {
    $messg = trim(shell_exec('dmesg | grep ch34') ?? '');
    if (strpos($messg, 'ch34x')) {
      $this->io->writeln('<fg=green>OK</> - ch34x');
    }
    if (strpos($messg, 'ch341')) {
      dump($messg);
      $this->io->writeln('<fg=red>FAIL</>: ch341. Expect <fg=green>ch34x</>, install CH340  driver');
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
    $config->setFlowControl(FALSE);
    $config->setCanonical(FALSE);
    return $config;
  }

}
