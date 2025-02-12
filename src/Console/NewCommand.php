<?php

namespace Jeffreyvr\TailPressInstaller\Console;

use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class NewCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new TailPress theme')
            ->addArgument('folder', InputArgument::REQUIRED)
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The name of your theme', false)
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialize a Git repository')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'The branch that should be created for a new repository', $this->defaultBranch())
            ->addOption('wordpress', null, InputOption::VALUE_NONE, 'Install WordPress.')
            ->addOption('dbname', null, InputOption::VALUE_OPTIONAL, 'The name of your database.')
            ->addOption('dbuser', null, InputOption::VALUE_OPTIONAL, 'The name of your database user.', 'root')
            ->addOption('dbpass', null, InputOption::VALUE_OPTIONAL, 'The password of your database.', 'root')
            ->addOption('dbhost', null, InputOption::VALUE_OPTIONAL, 'The host of your database.', 'localhost');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $commands = [];

        $output->write(PHP_EOL."<fg=blue>
  _____     _ _ ____
 |_   _|_ _(_) |  _ \ _ __ ___  ___ ___
   | |/ _` | | | |_) | '__/ _ \/ __/ __|
   | | (_| | | |  __/| | |  __/\__ \__ \
   |_|\__,_|_|_|_|   |_|  \___||___/___/'</>".PHP_EOL.PHP_EOL);

        $installWordPress = ($input->getOption('wordpress') || (new SymfonyStyle(
            $input,
            $output
        ))->confirm(
            'Would you like to install WordPress as well?',
            false
        ));

        $folder = $input->getArgument('folder');
        $slug = $this->determineSlug($folder);
        $prefix = $this->determineSlug($folder, true);

        $workingDirectory = $folder !== '.' ? getcwd().'/'.$folder : '.';

        if ($installWordPress) {
            $this->installWordPress($workingDirectory, $input, $output);

            $workingDirectory = "$workingDirectory/wp-content/themes/{$slug}";

            $commands[] = "mkdir \"$workingDirectory\"";
        } else {
            $commands[] = "mkdir \"$workingDirectory\"";
        }

        $commands[] = "cd \"$workingDirectory\"";
        $commands[] = "git clone -b 4.x https://github.com/jeffreyvr/tailpress.git . --q";

        if (($process = $this->runCommands($commands, $input, $output))->isSuccessful()) {
            if ($name = $input->getOption('name')) {
                $this->replaceInFile('TailPress', $name, $workingDirectory.'/style.css');
                $this->replaceInFile('tailpress', $prefix, $workingDirectory.'/style.css');

                $this->replaceInFile('tailpress_', $prefix.'_', $workingDirectory.'/functions.php');
                $this->replaceInFile('tailpress_', $prefix.'_', $workingDirectory.'/header.php');
                $this->replaceInFile('tailpress_', $prefix.'_', $workingDirectory.'/footer.php');

                $this->replacePackageJsonInfo($workingDirectory.'/package.json', 'name', $folder);
                $this->replacePackageJsonInfo($workingDirectory.'/package.json', 'text_domain', $folder);

                $this->replaceInFile('https://github.com/jeffreyvr/tailpress', 'https://github.com/username/' . $folder, $workingDirectory.'/package.json');
                $this->replaceInFile('tailpress.test', $folder . '.test', $workingDirectory.'/package.json');

                if (file_exists($workingDirectory.'/tailpress.json')) {
                    rename($workingDirectory.'/tailpress.json', $workingDirectory.'/'.$slug.'.json');
                }
            }

            $this->replaceThemeHeader($workingDirectory.'/style.css', 'Version', '0.1.0');
            $this->replaceThemeHeader(
                $workingDirectory.'/style.css',
                'Description',
                'A WordPress theme made with TailPress.'
            );
            $this->replacePackageJsonInfo($workingDirectory.'/package.json', 'version', '0.1.0');

            if ($installWordPress) {
                $this->replaceInFile('database_name_here', $input->getOption('dbname') ?? $prefix, $workingDirectory.'/../../../wp-config.php');
                $this->replaceInFile('username_here', $input->getOption('dbuser'), $workingDirectory.'/../../../wp-config.php');
                $this->replaceInFile('password_here', $input->getOption('dbpass'), $workingDirectory.'/../../../wp-config.php');
                $this->replaceInFile('localhost', $input->getOption('dbhost'), $workingDirectory.'/../../../wp-config.php');
                $this->replaceInFile("define( 'WP_DEBUG', false );", "define( 'WP_DEBUG', false );\ndefine( 'WP_ENVIRONMENT_TYPE', 'development' );", $workingDirectory.'/../../../wp-config.php');
            }

            $finalCommands = ["cd \"$workingDirectory\""];

            if (PHP_OS_FAMILY == 'Windows') {
                $finalCommands[] = "rmdir /S /Q .git";
            } else {
                $finalCommands[] = "rm -rf .git";
            }

            $finalCommands[] = "npm install --q --no-progress";

            $this->runCommands($finalCommands, $input, $output);

            if ($input->getOption('git')) {
                $this->createRepository($workingDirectory, $input, $output);
            }

            $output->writeln(PHP_EOL.'<info>Your theme is here: '.$workingDirectory.'</info>');

            $output->writeln(PHP_EOL.'<comment>Your boilerplate is ready, go create something beautiful!</comment>');
        }

        return $process->getExitCode();
    }

    protected function runCommands($commands, InputInterface $input, OutputInterface $output, array $env = [])
    {
        $process = Process::fromShellCommandline(implode(' && ', $commands), null, $env, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $output->writeln('Warning: '.$e->getMessage());
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write('    '.$line);
        });

        return $process;
    }

    protected function replaceInFile(string $search, string $replace, string $file)
    {
        file_put_contents(
            $file,
            str_replace($search, $replace, file_get_contents($file))
        );
    }

    protected function replaceThemeHeader(string $stylesheet, string $header, string $value)
    {
        $content = file_get_contents($stylesheet);

        $content = preg_replace('/'.$header.': (.*)/', $header . ': '.$value, $content);

        file_put_contents($stylesheet, $content);
    }

    protected function deleteFiles(string $workingDirectory, array $files)
    {
        foreach ($files as $file) {
            unlink($workingDirectory.'/'.$file);
        }
    }

    protected function replacePackageJsonInfo(string $packageJson, string $key, string $value)
    {
        $content = file_get_contents($packageJson);

        $content = preg_replace('/"'.$key.'": (.*)/', '"'.$key.'": "'.$value.'",', $content);

        file_put_contents($packageJson, $content);
    }

    protected function installWordPress(string $directory, InputInterface $input, OutputInterface $output)
    {
        $commands = [
            "mkdir $directory",
            "cd $directory",
            "curl -O https://wordpress.org/latest.tar.gz --no-progress-meter",
            "tar -zxf latest.tar.gz",
            "rm latest.tar.gz",
            "cd wordpress",
            "cp -rf . ..",
            "cd ..",
            "rm -R wordpress",
            "cp wp-config-sample.php wp-config.php"
        ];

        $this->runCommands($commands, $input, $output);
    }

    protected function createRepository(string $directory, InputInterface $input, OutputInterface $output)
    {
        chdir($directory);

        $branch = $input->getOption('branch') ?: $this->defaultBranch();

        $commands = [
            'git init -q',
            'git add .',
            'git commit -q -m "Initial commit"',
            "git branch -M {$branch}",
        ];

        $this->runCommands($commands, $input, $output);
    }

    protected function defaultBranch()
    {
        $process = new Process(['git', 'config', '--global', 'init.defaultBranch']);

        $process->run();

        $output = trim($process->getOutput());

        return $process->isSuccessful() && $output ? $output : 'main';
    }

    protected function determineSlug($folder, $sanitize = false)
    {
        $folder = explode('/', $folder);

        if (!$sanitize) {
            return end($folder);
        }

        return str_replace('-', '_', end($folder));
    }
}
