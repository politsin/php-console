<?php

namespace App\Command;

use App\Util\UartTrait;
use Fawno\PhpSerial\SerialDio;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Marlin G-code Mixer.
 */
class MixerCommand extends Command {

  use UartTrait;

  // phpcs:disable
  private SerialDio $serial;
  private SymfonyStyle $io;
  private string $mixPort = '/dev/ttyUSB0';
  // phpcs:enable

  /**
   * Config.
   */
  protected function configure() {
    $this->setName('mixer')->setDescription('php with marlin, oue!!');
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
    $this->io = $io;
    $map = [
      // 40 секунт на 50г, //.
      "X" => 50,
      // "Y" => 55,
      // "Z" => 150,
      // "E" => 40,
    ];
    $start = time();
    $this->mix($map);
    $this->io->writeln(time() - $start . "ms");
    return 0;
  }

  /**
   * Mix!
   */
  private function mix(array $map) {
    $pump = $this->initMixer();
    $cmd = "G0 ";
    foreach ($map as $key => $ml) {
      $steps = $ml * 266;
      if ($key == "E" && $steps > 200) {
        $this->io->error("SET: E = 200 steps | $ml=$steps");
        $steps = 200;
      }
      $cmd .= "$key-$steps ";
    }
    $cmd .= "F10000\r\n";
    $this->io->writeln($cmd);
    $pump->send($cmd);
    $pump->send("M18\r\n");
    $time = time();
    $k = 0;
    while (TRUE) {
      $ok = FALSE;
      foreach (explode("\n", $pump->read()) as $line) {
        $line = trim($line);
        if ($line == 'ok') {
          $ok = TRUE;
          dump("ok");
          break;
        }
        if ($line) {
          $k++;
          $time = time();
          dump("$k | $line");
        }
      }
      if ($ok) {
        // break; //.
      }
      if (time() > $time + 3) {
        dump("done");
        break;
      }
      usleep(300 * 1000);
    }
  }

  /**
   * Loop.
   */
  private function initMixer() : SerialDio {
    $this->io->writeln("initMixer: start " . $this->mixPort);
    $this->resetSerial('1a86:7523');
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
    $this->io->writeln("initMixer: done " . $this->mixPort);
    $pump->send("G91\r\n");
    // Cold extrudes are disabled (min temp 170C)
    $pump->send("M302 S0 P1\r\n");
    return $pump;
  }

  /**
   * Command and wait ok.
   */
  protected function runTty($io) {

    $commands = [
      "G91",
      "G4 P100",
      "G0 X-35 F3000",
      "G4 S1",
      "G0 Y-54 F3000",
      "G4 S1",
      "G0 Z-37 F3000",
      "G4 S1",
      "G0 E-200 F10000",
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

}
