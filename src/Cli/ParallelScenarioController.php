<?php

namespace DMarynicz\BehatParallelExtension\Cli;

use Behat\Gherkin\Node\ScenarioLikeInterface;
use Behat\Testwork\Cli\Controller;
use Behat\Testwork\Tester\Cli\ExerciseController;
use DMarynicz\BehatParallelExtension\Event\AfterTaskTested;
use DMarynicz\BehatParallelExtension\Event\BeforeTaskTested;
use DMarynicz\BehatParallelExtension\Event\ParallelTestCompleted;
use DMarynicz\BehatParallelExtension\Exception\UnexpectedValue;
use DMarynicz\BehatParallelExtension\Finder\ScenarioSpecificationsFinder;
use DMarynicz\BehatParallelExtension\Service\EventDispatcherDecorator;
use DMarynicz\BehatParallelExtension\Task\ArgumentsBuilder;
use DMarynicz\BehatParallelExtension\Task\Queue;
use DMarynicz\BehatParallelExtension\Task\Task;
use DMarynicz\BehatParallelExtension\Worker\WorkerPoll;
use ReflectionException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Process\Process;

class ParallelScenarioController implements Controller
{
    const SERVICE_ID = 'cli.controller.parallel_extension.parallel_scenario_exercise';

    /** @var ExerciseController */
    private $decoratedExerciseController;

    /** @var ScenarioSpecificationsFinder */
    private $specificationFinder;

    /** @var ArgumentsBuilder */
    private $argumentsBuilder;

    /** @var Queue */
    private $queue;

    /** @var WorkerPoll */
    private $poll;

    /** @var EventDispatcherInterface */
    private $eventDispatcher;

    /** @var int */
    private $exitCode = 0;

    /** @var ProgressBar */
    private $progressBar;

    /** @var OutputInterface */
    private $output;

    /** @var InputInterface */
    private $input;

    public function __construct(
        ExerciseController $decoratedExerciseController,
        ScenarioSpecificationsFinder $specificationsFinder,
        ArgumentsBuilder $argumentsBuilder,
        WorkerPoll $poll,
        Queue $queue,
        EventDispatcherDecorator $eventDispatcher
    ) {
        $this->decoratedExerciseController = $decoratedExerciseController;
        $this->specificationFinder         = $specificationsFinder;
        $this->argumentsBuilder            = $argumentsBuilder;
        $this->queue                       = $queue;
        $this->poll                        = $poll;
        $this->eventDispatcher             = $eventDispatcher;
    }

    /**
     * {@inheritDoc}
     */
    public function configure(SymfonyCommand $command)
    {
        $this->decoratedExerciseController->configure($command);

        $command->addOption(
            'parallel',
            'l',
            InputOption::VALUE_OPTIONAL,
            'How many scenario jobs run in parallel? Available values empty or integer',
            false
        )
            ->addUsage('--parallel 8')
            ->addUsage('--parallel');
    }

    /**
     * @return int|null
     *
     * @throws ReflectionException
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $startInParallel = $input->getOption('parallel') !== false;
        if (! $startInParallel) {
            return $this->decoratedExerciseController->execute($input, $output);
        }

        $poolSize = $this->getMaxPoolSize($input);
        $output->writeln(sprintf('Starting parallel scenario tests with %d workers', $poolSize));
        $this->poll->setMaxWorkers($poolSize);

        $this->output = $output;
        $this->input  = $input;

        $this->eventDispatcher->addListener(BeforeTaskTested::BEFORE, [$this, 'beforeTaskTested']);
        $this->eventDispatcher->addListener(AfterTaskTested::AFTER, [$this, 'afterTaskTested']);

        $tasks = $this->findScenarios($input);
        foreach ($tasks as $task) {
            $task->setCommand($this->argumentsBuilder->buildArguments($input, $task->getPath()));
            $this->queue->enqueue($task);
        }

        $this->progressBar = new ProgressBar($output, count($tasks));
        ProgressBar::setFormatDefinition(
            'custom',
            " %feature%\n  %scenario%\n %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%"
        );

        $this->progressBar->setMessage('<info>Starting</info>', 'feature');
        $this->progressBar->setMessage('', 'scenario');
        $this->progressBar->setFormat('custom');
        $this->progressBar->start();

        $this->poll->start();
        $this->poll->wait();
        $output->writeln('');

        $this->eventDispatcher->dispatch(new ParallelTestCompleted(), ParallelTestCompleted::COMPLETED);

        return $this->exitCode;
    }

    public function beforeTaskTested(BeforeTaskTested $beforeTaskTested)
    {
        $task          = $beforeTaskTested->getTask();
        $featureTitle  = sprintf('<info>Feature: %s</info>', $task->getFeature()->getTitle());
        $scenarioTitle = '';
        if ($task->getScenario() instanceof ScenarioLikeInterface) {
            $scenarioTitle = sprintf('<info>Scenario: %s</info>', $task->getScenario()->getTitle());
        }

        $this->progressBar->setMessage($featureTitle, 'feature');
        $this->progressBar->setMessage($scenarioTitle, 'scenario');
    }

    public function afterTaskTested(AfterTaskTested $taskTested)
    {
        $this->progressBar->advance();
        $process = $taskTested->getProcess();
        if ($process->isSuccessful()) {
            return;
        }

        $this->exitCode = 1;
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->output->write("\n" . $output);

        if ($this->input->getOption('stop-on-failure') !== false) {
            $this->poll->stop();
        }
    }

    /**
     * @return Task[]
     */
    private function findScenarios(InputInterface $input)
    {
        $path = $input->hasArgument('path') ? $input->getArgument('path') : null;
        if (! is_string($path) && $path !== null) {
            throw new UnexpectedValue('Expected string or null');
        }

        return $this->specificationFinder->findScenarios($path);
    }

    /**
     * @return int
     */
    private function getNumberOfProcessingUnitsAvailable()
    {
        $nproc = new Process(['nproc']);
        $nproc->run();
        if (! $nproc->isSuccessful()) {
            return 1;
        }

        return (int) trim($nproc->getOutput());
    }

    /**
     * @return int
     */
    private function getMaxPoolSize(InputInterface $input)
    {
        $maxSize = is_array($input->getOption('parallel')) ?
            (int) $input->getOption('parallel')[0] :
            (int) $input->getOption('parallel');
        $maxSize = $maxSize > 0 ? $maxSize : $this->getNumberOfProcessingUnitsAvailable();

        return $maxSize;
    }
}