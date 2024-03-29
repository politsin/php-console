<?php

namespace App\Command;

use App\Util\ExecTrait;
use App\Util\UartTrait;
use Fawno\PhpSerial\SerialDio;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Scale Command.
 */
class ScaleCommand extends Command {

  use UartTrait;
  use ExecTrait;
  // phpcs:disable
  private SerialDio $serial;
  private SymfonyStyle $io;
  private int $scale = 0;
  private string $mixPort = '/dev/ttyUSB0';
  private string $scalePort = '/dev/ttyUSB1';
  //phpcs:enable;

  /**
   * Config.
   */
  protected function configure() {
    $this->setName('scale')->setDescription('Demo');
  }

  /**
   * Exec.
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = new SymfonyStyle($input, $output);
    $this->io = $io;
    $this->usbList();
    if (0) {
      $this->mixPort = "/dev/ttyUSB1";
      $this->scalePort = "/dev/ttyUSB0";
    }
    $pump = $this->initMixer();
    $scale = $this->initScale();
    usleep(100 * 1000);
    $pump->send("M18\r\n");
    $this->readData($scale);
    $this->loop($scale, $pump);
    return 0;
  }

  /**
   * Loop.
   */
  private function initScale() : SerialDio {
    $this->io->writeln("initScale: start " . $this->scalePort);
    // $this->resetSerial('214b:7250');
    return $this->initSerial($this->scalePort);
  }

  /**
   * Loop.
   */
  private function initMixer() : SerialDio {
    $this->io->writeln("initMixer: start " . $this->mixPort);
    // $this->resetSerial('1a86:7523');
    $pump = $this->initSerial($this->mixPort);
    $state = '';
    while ($state != "echo") {
      $pump->send("M118 ECHO-INIT\r\n");
      foreach (explode("\n", $pump->read()) as $line) {
        $line = trim($line);
        if (strpos($line, "ECHO-INIT") !== FALSE) {
          $state = 'echo';
        }
        elseif ($line) {
          $this->io->writeln($line);
        }
      }
      usleep(700 * 1000);
    }
    $pump->send("G91\r\n");

    $pump->send("G0 X-12000 F10000\r\n");
    return $pump;
  }

  /**
   * Loop.
   */
  private function loop(SerialDio $scale, SerialDio $pump) {
    $delay_ms = 1000;
    $this->io->writeln("Loop start");
    $ok = "";
    while ($ok != "ok") {
      $data = $this->readData($scale);
      if ($this->scale < 30) {
        $pump->send("G0 X-20 F5000\r\n");
      }
      else {
        $this->io->writeln("DONE!");
        $pump->send("M18\r\n");
      }
      usleep($delay_ms * 1000);
    }
  }

  /**
   * Loop.
   */
  private function readData(SerialDio $serial) : array {
    $result = [];
    foreach (explode("\n", $serial->read()) as $line) {
      $line = substr(trim($line), 3, -3);
      if ($line) {
        $yaml = str_replace(":", ": ", implode("\n", explode(" ", $line)));
        try {
          $data = Yaml::parse($yaml);
          if (NULL !== $scale = $this->parseScale($data)) {
            $this->io->writeln("Scale: $scale");
          }
          $result[] = $data;
        }
        catch (\Throwable $th) {
          $this->io->writeln($th->getMessage());
        }
      }
    }
    return $result;
  }

  /**
   * Parse scale val.
   */
  private function parseScale(array $data) : int | NULL {
    $scale = NULL;
    if (count($data) == 7) {
      $scale = round($data['SCALE'] / 1000, 1);
      if ($scale < 0 && $scale > -2) {
        $scale = 0;
      }
      $this->scale = $scale;
    }
    return $scale;
  }

}
