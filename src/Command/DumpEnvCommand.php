<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Command;

use Composer\Command\BaseCommand;
use Composer\Config;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpProcess;
use Symfony\Flex\Options;

class DumpEnvCommand extends BaseCommand
{
    private $config;
    private $options;

    public function __construct(Config $config, Options $options)
    {
        $this->config = $config;
        $this->options = $options;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('symfony:dump-env')
            ->setAliases(['dump-env'])
            ->setDescription('Compiles .env files to .env.local.php.')
            ->setDefinition([
                new InputArgument('env', InputArgument::REQUIRED, 'The application environment to dump .env files for - e.g. "prod".'),
            ])
            ->addOption('empty', null, InputOption::VALUE_NONE, 'Ignore the content of .env files')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $_SERVER['APP_ENV'] = $env = $input->getArgument('env');
        $path = $this->options->get('root-dir').'/.env';

        $vars = $input->getOption('empty') ? ['APP_ENV' => $env] : $this->loadEnv($path, $env);
        $vars = var_export($vars, true);
        $vars = <<<EOF
<?php

// This file was generated by running "composer dump-env $env"

return $vars;

EOF;
        file_put_contents($path.'.local.php', $vars, LOCK_EX);

        $this->getIO()->writeError('Successfully dumped .env files in <info>.env.local.php</>');
    }

    private function loadEnv(string $path, string $env): array
    {
        if (!file_exists($autoloadFile = $this->config->get('vendor-dir').'/autoload.php')) {
            throw new \RuntimeException(sprintf('Please run "composer install" before running this command: "%s" not found.', $autoloadFile));
        }

        $php = <<<'EOPHP'
<?php

use Symfony\Component\Dotenv\Dotenv;

require %s;

if (!class_exists(Dotenv::class)) {
    exit;
}

foreach ($_SERVER as $k => $v) {
    if (\is_string($v) && false !== getenv($k)) {
        unset($_SERVER[$k]);
        putenv($k);
    }
}

$path = %s;
$env = %s;
$dotenv = new Dotenv();
$_ENV = ['APP_ENV' => $env];

if (method_exists($dotenv, 'loadEnv')) {
    $dotenv->loadEnv($path);
} else {
    // fallback code in case your Dotenv component is not 4.2 or higher (when loadEnv() was added)
    $dotenv->load(file_exists($path) || !file_exists($p = "$path.dist") ? $path : $p);

    if ('test' !== $env && file_exists($p = "$path.local")) {
        $dotenv->load($p);
    }

    if (file_exists($p = "$path.$env")) {
        $dotenv->load($p);
    }

    if (file_exists($p = "$path.$env.local")) {
        $dotenv->load($p);
    }
}

unset($_ENV['SYMFONY_DOTENV_VARS']);

echo serialize($_ENV);

EOPHP;

        $php = sprintf($php, var_export($autoloadFile, true), var_export($path, true), var_export($env, true));
        $process = new PhpProcess($php);
        if (method_exists($process, 'inheritEnvironmentVariables')) {
            $process->inheritEnvironmentVariables();
        }
        $process->mustRun();

        if (!$env = $process->getOutput()) {
            throw new \RuntimeException('Please run "composer require symfony/dotenv" to load the ".env" files configuring the application.');
        }

        return unserialize($env);
    }
}
