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
  private string $port = '/dev/ttyUSB0';
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
    $this->usbList(TRUE);
    $pump = $this->initMixer();
    $scale = $this->initScale();
    usleep(100 * 1000);
    $this->readData($scale);
    // $this->loop($scale);
    return 0;
  }

  /**
   * Loop.
   */
  private function initScale() : SerialDio {
    $this->resetSerial('214b:7250');
    return $this->initSerial('/dev/ttyUSB1');
  }

  /**
   * Loop.
   */
  private function initMixer() : SerialDio {
    $this->io->writeln("initMixer: start");
    $this->resetSerial('1a86:7523');
    $pump = $this->initSerial('/dev/ttyUSB0');
    $init = '';
    while ($init == "hello!!!") {
      $pump->send("M118 hello!!!\r\n");
      $this->io->writeln("initMixer: ping");
      foreach (explode("\n", $pump->read()) as $line) {
        $data = trim($line);
        $this->io->text($data);
      }
      usleep(100 * 1000);
    }
    $pump->send("G91");
    return $pump;
  }

  /**
   * Loop.
   */
  private function loop(SerialDio $serial, $delay_ms = 100) {
    $this->io->writeln("Loop start");
    $ok = "";
    while ($ok != "ok") {
      $data = $this->readData($serial);
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
    }
    return $scale;
  }

}
