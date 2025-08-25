<?php

namespace App\Command;

use App\Util\ExecTrait;
use App\Util\UartTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Uart Health Command.
 */
class UartHealthCommand extends Command {

  use UartTrait;
  use ExecTrait;

  private string $port = '/dev/ttyUSB0';
  private SymfonyStyle $io;

  /**
   * Config.
   */
  protected function configure() {
    $this->setName('uart')->setDescription('Uart Health');
  }

  /**
   * Exec.
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $this->io = $io;
    $usb = $this->usbList();
    dump($usb);
    $this->io->writeln("-------");
    return 0;
  }

}
