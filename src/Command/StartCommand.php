<?php

namespace App\Command;

use App\Service\PhpSerial;
use App\Util\UartTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * GNSS.
 */
class StartCommand extends Command {

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
    $this->setName('start')
      ->setDescription('php-serial');
  }

  /**
   * Exec.
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $io->section('Start');
    $this->io = $io;
    // $this->usbList(TRUE);
    // $this->driverTest();
    $this->io->writeln($this->resetSerial('1a86:7523'));
    $serial = new PhpSerial();
    $serial->deviceSet($_ENV['SERIAL_COM']);
    $serial->confBaudRate($_ENV['SERIAL_BADU']);
    // $serial->confParity("none");
    // $serial->confCharacterLength(8);
    // $serial->confStopBits(1);
    // $serial->confFlowControl("none");
    // Then we need to open it.
    $serial->deviceOpen();

    // To write into.
    $serial->sendMessage("G0 X50 Y40 F600");
    // To read.
    $result = $serial->readLine();
    $this->io->writeln($result);
    return Command::SUCCESS;
  }

}
