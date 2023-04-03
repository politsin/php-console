<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Fawno\Modbus\ModbusRTU;
use Fawno\PhpSerial\Config\BaudRates;
use Fawno\PhpSerial\Config\DataBits;
use Fawno\PhpSerial\Config\Parity;
use Fawno\PhpSerial\Config\StopBits;
use Fawno\PhpSerial\SerialConfig;

/**
 * Update.
 */
class ModBusCommand extends Command {

  /**
   * Config.
   */
  protected function configure() {
    $this
      ->setName('modbus')
      ->setDescription('php with modbus, oue!!');
  }

  /**
   * Exec.
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = new SymfonyStyle($input, $output);
    $io->section('Hello');
    $config = $this->getConfig();
    $modbus = new ModbusRTU('/dev/ttyUSB0', $config);
    $modbus->open();
    $reg = 4352;
    $result = $modbus->writeSingleRegister(2, $reg + 5, 07);
    $result = $modbus->readHoldingRegisters(2, $reg, 6);
    print_r($result);
    return 0;
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
