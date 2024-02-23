<?php

namespace App\Util;

/**
 * Uart Health Command.
 */
trait GpioTrait {

  /**
   * Test Whoami.
   */
  private function testWhoami() {
    $name = $this->exec(['/usr/bin/whoami']);
    $this->io->section("Uart as $name");
  }

  /**
   * Config.
   */
  protected function install() {
    // Apt install setserial.
    // apt install wiringpi
    // https://github.com/orangepi-xunlong/wiringOP
    // http://psenyukov.ru/%D1%80%D0%B0%D0%B1%D0%BE%D1%82%D0%B0-%D1%81-gpio-%D1%80%D0%B0%D0%B7%D1%8A%D0%B5%D0%BC%D0%B0%D0%BC%D0%B8-%D0%B2-orange-pi/
    // https://github.com/tumugin/WiringOP/tree/h5
    // fix https://forum.armbian.com/topic/6197-hardware-line-is-missing-on-proccpuinfo/
    // cd ~
    // git clone -b ubuntu https://github.com/juliagoda/CH341SER.git
    // cd CH341SER.
    // хз что дальше делать, не ставится на Ubuntu 22.04
    // gpio mode $PORT out
    // gpio write $PORT 0;        ## LED on
    // gpio write $PORT 1;        ## LED off
    // x.
    $this->setName('uart')->setDescription('Uart Health');
  }

}
