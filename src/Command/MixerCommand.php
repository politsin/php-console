<?php

namespace App\Command;

use App\Util\UartTrait;
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
  protected function execute(InputInterface $input, OutputInterface $output): int {
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
  }

  /**
   * Loop.
   */
  private function initMixer() {
    $this->io->writeln("initMixer: start " . $this->mixPort);
    $this->resetSerial('1a86:7523');
    $pump = $this->initSerial($this->mixPort);
    $state = '';
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
    usleep(100 * 1000);
  }

}
