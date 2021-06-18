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
        $config->getPresenter()->addCasters(
            $this->getCasters()
        );

        $shell = new Shell($config);

        $shell->addCommands($this->getCommands());
        $shell->setIncludes($this->input->getArgument('include'));

        $path = env('COMPOSER_VENDOR_DIR', BASE_PATH . DIRECTORY_SEPARATOR . 'vendor');
        $path .= '/composer/autoload_classmap.php';

        $loader = ClassAliasAutoloader::register(
            $shell,
            $path,
            $this->config->get('tinker.alias', []),
            $this->config->get('tinker.dont_alias', [])
        );

        if ($code = $this->input->getOption('execute')) {
            try {
                $shell->setOutput($this->output);
                $shell->execute($code);
            } finally {
                $loader->unregister();
            }

            return 0;
        }

        try {
            return $shell->run();
        } finally {
            $loader->unregister();
        }
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

    /**
     * Get an array of Laravel tailored casters.
     *
     * @return array
     */
    protected function getCasters()
    {
        $casters = [
            'Hyperf\Utils\Collection' => 'FriendsOfHyperf\Tinker\TinkerCaster::castCollection',
            // 'Illuminate\Support\HtmlString' => 'FriendsOfHyperf\Tinker\TinkerCaster::castHtmlString',
            // 'Illuminate\Support\Stringable' => 'FriendsOfHyperf\Tinker\TinkerCaster::castStringable',
        ];

        if (class_exists('Hyperf\DbConnection\Model\Model')) {
            $casters['Hyperf\DbConnection\Model\Model'] = 'FriendsOfHyperf\Tinker\TinkerCaster::castModel';
        }

        if (class_exists('Symfony\Component\Console\Application')) {
            $casters['Symfony\Component\Console\Application'] = 'FriendsOfHyperf\Tinker\TinkerCaster::castApplication';
        }

        return $casters;
    }
}
