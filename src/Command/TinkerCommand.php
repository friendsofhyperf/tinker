<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-tinker.
 *
 * @link     https://github.com/friendsofhyperf/tinker
 * @document https://github.com/friendsofhyperf/tinker/blob/2.x/README.md
 * @contact  huangdijia@gmail.com
 */
namespace FriendsOfHyperf\Tinker\Command;

use FriendsOfHyperf\Tinker\ClassAliasAutoloader;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;
use Psy\Configuration;
use Psy\Shell;
use Psy\VersionUpdater\Checker;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class TinkerCommand extends HyperfCommand
{
    /**
     * Commands to include in the tinker shell.
     *
     * @var array
     */
    protected $commandWhitelist = [
        'migrate',
    ];

    /** @var ContainerInterface */
    protected $container;

    /**
     * @var ConfigInterface
     */
    protected $config;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->config = $container->get(ConfigInterface::class);

        parent::__construct('tinker');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Interact with your application');
        $this->addOption('execute', null, InputOption::VALUE_OPTIONAL, 'Execute the given code using Tinker');
        $this->addArgument('include', InputArgument::IS_ARRAY, 'Include file(s) before starting tinker');
    }

    public function handle()
    {
        $config = Configuration::fromInput($this->input);
        $config->setUpdateCheck(Checker::NEVER);
        $config->setUsePcntl((bool) $this->config->get('tinker.usePcntl', false));

        $shell = new Shell($config);

        $shell->addCommands($this->getCommands());
        $shell->setIncludes($this->input->getArgument('include'));

        $path = env('COMPOSER_VENDOR_DIR', BASE_PATH . DIRECTORY_SEPARATOR . 'vendor');
        $path .= '/composer/autoload_classmap.php';

        $config = $this->container->get(ConfigInterface::class);

        $loader = ClassAliasAutoloader::register(
            $shell,
            $path,
            $config->get('tinker.alias', []),
            $config->get('tinker.dont_alias', [])
        );

        if ($code = $this->input->getOption('execute')) {
            $shell->setOutput($this->output);
            $shell->execute($code);

            return 0;
        }

        return $shell->run();
    }

    /**
     * @throws LogicException
     * @throws CommandNotFoundException
     * @return SymfonyCommand[]
     */
    protected function getCommands()
    {
        /** @var \Symfony\Component\Console\Application $application */
        $application = $this->container->get(ApplicationInterface::class);
        $commands = [];

        $this->commandWhitelist = array_merge($this->commandWhitelist, (array) $this->config->get('tinker.command_white_list', []));

        foreach ($application->all() as $name => $command) {
            if (in_array($name, $this->commandWhitelist)) {
                $commands[] = $command;
            }
        }

        foreach ($this->config->get('tinker.commands', []) as $command) {
            $commands[] = $this->container->get($command);
        }

        return $commands;
    }
}
