<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Update.
 */
class ScaleCommand extends Command {

  /**
   * Config.
   */
  protected function configure() {
    $this
      ->setName('scale')
      ->setDescription('Demo')
      ->addArgument('text', InputArgument::OPTIONAL, 'Input text')
      ->addOption('commit');
  }

  /**
   * Exec.
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $number = 1;
    $io = new SymfonyStyle($input, $output);
    $io->section('Table');
    $table = new Table($output);
    $table
      ->setHeaderTitle('Books')
      ->setHeaders(['ISBN', 'Title', 'Author'])
      ->setColumnWidths([10, 0, 30])
      ->setRows([
        ['99921-58-10-7', 'Divine Comedy', 'Dante Alighieri'],
        ['9971-5-0210-0', 'A Tale of Two Cities', 'Charles Dickens'],
        ['960-425-059-0', 'The Lord of the Rings', 'J. R. R. Tolkien'],
        new TableSeparator(),
        ['80-902734-1-6', 'And Then There Were None', 'Agatha Christie'],
      ]);
    $table->render();

    return 0;
  }

}
