<?php

namespace App\Util;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Uart Health Command.
 */
trait ExecTrait {

  /**
   * Test Whoami.
   */
  private function testWhoami() {
    $name = $this->exec(['/usr/bin/whoami']);
    $this->io->section("Uart as $name");
  }

  /**
   * Current data.
   */
  protected function exec(array $cmd, float $timeout = 999999) : string {
    $process = new Process($cmd, NULL, [
      'DEBIAN_FRONTEND' => 'noninteractive',
    ]);
    $process->setTimeout($timeout);
    if (TRUE) {
      // dump(implode(" ", $cmd));
      // return "";.
    }
    $process->run();
    if (!$process->isSuccessful()) {
      throw new ProcessFailedException($process);
    }
    return $process->getOutput();
  }

}
