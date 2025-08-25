<?php

namespace App\Command;

use App\Util\UartTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * GNSS.
 */
class MarlinShRun extends Command {

  use UartTrait;

  // phpcs:disable
  private SymfonyStyle $io;
  private string $port = '/dev/ttyACM0';
  private int $count = 0;
  private array $gsv = [];
  // phpcs:enable

  /**
   * Config.
   */
  protected function configure() {
    $this->setName('marlin')
      ->setDescription('marlin');
  }

  /**
   * Exec.
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $io->section('Start');
    $this->io = $io;
    $commands = [
      'stty -F /dev/ttyUSB0 115200 cs8 -cstopb -parenb',
      'echo -e "G91\r\n" > /dev/ttyUSB0',
      'echo -e "G0 X50 Y40 F600\r" > /dev/ttyUSB0',
    ];
    foreach ($commands as $cmd) {
      $result = shell_exec($cmd);
      $io->writeln("");
    }
    return Command::SUCCESS;
  }

}
