<?php

namespace TailPress\Installer\Console;

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
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The name of your theme')
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialize a Git repository')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'The branch that should be created for a new repository', $this->defaultBranch())
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Use development version of your TailPress.')
            ->addOption('wordpress', null, InputOption::VALUE_NONE, 'Install WordPress')
            ->addOption('dbname', null, InputOption::VALUE_OPTIONAL, 'The name of your database')
            ->addOption('dbuser', null, InputOption::VALUE_OPTIONAL, 'The name of your database user')
            ->addOption('dbpass', null, InputOption::VALUE_OPTIONAL, 'The password of your database')
            ->addOption('dbhost', null, InputOption::VALUE_OPTIONAL, 'The host of your database')
            ->addOption('author-name', null, InputOption::VALUE_OPTIONAL, 'The name of the theme author')
            ->addOption('author-email', null, InputOption::VALUE_OPTIONAL, 'The email of the theme author')
            ->addOption('local-dev-url', null, InputOption::VALUE_OPTIONAL, 'The local development url of your site');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $commands = [];

        $output->write(PHP_EOL."<fg=blue>
  _____     _ _ ____
 |_   _|_ _(_) |  _ \ _ __ ___  ___ ___
   | |/ _` | | | |_) | '__/ _ \/ __/ __|
   | | (_| | | |  __/| | |  __/\__ \__ \
   |_|\__,_|_|_|_|   |_|  \___||___/___/'</>".PHP_EOL.PHP_EOL);

        $folder = $input->getArgument('folder');

        $name = $input->getOption('name') ?? (new SymfonyStyle($input, $output))->ask('What is the name of your theme?', $folder);
        $authorName = $input->getOption('author-name') ?? (new SymfonyStyle($input, $output))->ask('What is the theme author name?', 'Jeffrey van Rossum');
        $authorEmail = $input->getOption('author-email') ?? (new SymfonyStyle($input, $output))->ask('What is the theme author email?', 'jeffrey@vanrossum.dev');
        $localDevUrl = $input->getOption('local-dev-url') ?? (new SymfonyStyle($input, $output))->ask('What is the local development url of your site?', 'http://localhost:8000');

        $installWordPress = ($input->getOption('wordpress') || (new SymfonyStyle($input, $output))->confirm('Would you like to install WordPress as well?', false));

        if($installWordPress) {
            $dbName = $input->getOption('dbname') ?? (new SymfonyStyle($input, $output))->ask('What is the name of your database?', str_replace('-', '_', $folder));
            $dbUser = $input->getOption('dbuser') ?? (new SymfonyStyle($input, $output))->ask('What is the user of your database?', 'root');
            $dbPass = $input->getOption('dbpass') ?? (new SymfonyStyle($input, $output))->ask('What is the password of your database?', 'root');
            $dbHost = $input->getOption('dbhost') ?? (new SymfonyStyle($input, $output))->ask('What is the host of your database?', 'localhost');
        }

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

        $version = '';
        if($input->getOption('dev')) {
            $version = '5.x-dev';
        }

        $commands[] = "composer create-project tailpress/tailpress \"$workingDirectory\" {$version} --remove-vcs --prefer-dist --no-scripts";
        $commands[] = "cd \"$workingDirectory\"";

        if (($process = $this->runCommands($commands, $input, $output))->isSuccessful()) {
            $this->replacePackageJsonInfo($workingDirectory.'/package.json', 'name', $prefix);
            $this->replacePackageJsonInfo($workingDirectory.'/package.json', 'text_domain', $prefix);
            $this->replacePackageJsonInfo($workingDirectory.'/package.json', 'version', '0.1.0');
            $this->replacePackageJsonInfo($workingDirectory.'/package.json', 'author', $authorName);

            $this->replaceInFile('Jeffrey van Rossum', $authorName, $workingDirectory.'/composer.json');
            $this->replaceInFile('jeffrey@vanrossum.dev', $authorEmail, $workingDirectory.'/composer.json');

            if (file_exists($workingDirectory.'/vite.config.mjs')) {
                $this->replaceInFile('http://tailpress.test', $localDevUrl, $workingDirectory.'/vite.config.mjs');
                $this->replaceInFile('wp-content/themes/tailpress', "wp-content/themes/{$prefix}", $workingDirectory.'/vite.config.mjs');
            }

            $this->replaceThemeHeader($workingDirectory.'/style.css', 'Theme Name', $name, $workingDirectory.'/style.css');
            $this->replaceThemeHeader($workingDirectory.'/style.css', 'Author', $authorName, $workingDirectory.'/style.css');
            $this->replaceThemeHeader($workingDirectory.'/style.css', 'Text Domain', $prefix, $workingDirectory.'/style.css');

            $this->replaceThemeHeader($workingDirectory.'/style.css', 'Description', 'A WordPress theme made with TailPress.');
            $this->replaceThemeHeader($workingDirectory.'/style.css', 'Version', '0.1.0');

            if ($installWordPress) {
                $this->replaceInFile('database_name_here', $dbName, $workingDirectory.'/../../../wp-config.php');
                $this->replaceInFile('username_here', $dbUser, $workingDirectory.'/../../../wp-config.php');
                $this->replaceInFile('password_here', $dbPass, $workingDirectory.'/../../../wp-config.php');
                $this->replaceInFile('localhost', $dbHost, $workingDirectory.'/../../../wp-config.php');
                $this->replaceInFile("define( 'WP_DEBUG', false );", "define( 'WP_DEBUG', false );\ndefine( 'WP_ENVIRONMENT_TYPE', 'development' );", $workingDirectory.'/../../../wp-config.php');
            }

            $finalCommands = ["cd \"$workingDirectory\""];

            if (PHP_OS_FAMILY == 'Windows') {
                $finalCommands[] = "rmdir /S /Q .git";
            } else {
                $finalCommands[] = "rm -rf .git";
            }

            if (file_exists($workingDirectory.'/composer.json')) {
                $finalCommands[] = 'composer install';
            }

            $finalCommands[] = "npm install --q --no-progress";

            $finalCommands[] = "npm run build";

            $this->runCommands($finalCommands, $input, $output);

            if ($input->getOption('git')) {
                $this->createRepository($workingDirectory, $input, $output);
            }

            $output->writeln(PHP_EOL.'<comment>🌊 Your boilerplate is ready, go create something beautiful!</comment>');
            $output->writeln(PHP_EOL.'<info>🏗️ Your theme is here: '.$workingDirectory.'</info>');
            $output->writeln(PHP_EOL.'<comment>✨ If you like TailPress, please consider starring the repo at https://github.com/tailpress/tailpress</comment>');
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
