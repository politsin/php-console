<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Update.
 */
class TestCommand extends Command {

  /**
   * Config.
   */
  protected function configure() {
    $this
      ->setName('test')
      ->setDescription('Demo')
      ->addArgument('text', InputArgument::OPTIONAL, 'Input text')
      ->addOption('commit');
  }

  /**
   * Exec.
   */
  protected function execute(InputInterface $input, OutputInterface $output) {

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

    $progressBar = new ProgressBar($output, 50);
    $progressBar->start();
    $i = 0;
    while ($i++ < 50) {
      usleep(50000);
      $progressBar->advance();
    }
    // Ensures that the progress bar is at 100%.
    $progressBar->finish();
    if (false) {
    $io->section('Echo cmd');
    $command = $this->getApplication()->find('test:echo');
    $arguments = [
      'command' => 'test:echo',
      'text'    => 'Fabien',
      // '--yell'  => TRUE,
    ];
    $greetInput = new ArrayInput($arguments);
    $returnCode = $command->run($greetInput, $output);

    $io->title('OutputFormatterStyle');
    $outputStyle = new OutputFormatterStyle('red', 'yellow', ['bold', 'blink']);
    $output->getFormatter()->setStyle('fire', $outputStyle);
    $output->writeln('<fire>foo</>');
    $output->writeln('<fg=green>foo</>');
    $output->writeln('<fg=black;bg=cyan>foo</>');
    $output->writeln('<bg=yellow;options=bold>foo</>');
    $output->writeln('<options=bold,underscore>foo</>');
    $output->writeln('<href=https://symfony.com>Symfony Homepage</>');

    $io->text('Lorem ipsum dolor sit amet');
    $io->text([
      'Lorem ipsum dolor sit amet',
      'Consectetur adipiscing elit',
      'Aenean sit amet arcu vitae sem faucibus porta',
    ]);
    $io->listing([
      'Element #1 Lorem ipsum dolor sit amet',
      'Element #2 Lorem ipsum dolor sit amet',
      'Element #3 Lorem ipsum dolor sit amet',
    ]);
    $io->definitionList(
      'This is a title',
      ['foo1' => 'bar1'],
      ['foo2' => 'bar2'],
      ['foo3' => 'bar3'],
      new TableSeparator(),
      'This is another title',
      ['foo4' => 'bar4']
    );

    $io->note('Lorem ipsum dolor sit amet');
    $io->caution('=)');
    $io->ask('Number of workers to start', 1, function ($number) {
      if (!is_numeric($number)) {
        throw new \RuntimeException('You must type a number.');
      }
      return (int) $number;
    });
    $io->success([
      'Lorem ipsum dolor sit amet',
      'Consectetur adipiscing elit',
    ]);
    $io->warning("start $number");

    $io->title('Choose one');
    $question = new ChoiceQuestion(
      'Please select your favorite color (defaults to red)',
      ['red', 'blue', 'yellow'],
      0
    );
    $question->setErrorMessage('Color %s is invalid.');
    $color = $helper->ask($input, $output, $question);
    $output->writeln("You have just selected: $color");
    }

    return 0;
  }

}
