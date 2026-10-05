<?php

namespace App\Command;

use App\Entity\Admin;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée un administrateur ou change son mot de passe depuis le terminal :
 *   php bin/console app:admin:password admin
 */
#[AsCommand(name: 'app:admin:password', description: 'Crée un administrateur ou réinitialise son mot de passe')]
class AdminPasswordCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Identifiant de l\'administrateur');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = trim($input->getArgument('username'));

        $password = $io->askHidden('Nouveau mot de passe (8 caractères minimum)');
        if (!$password || mb_strlen($password) < 8) {
            $io->error('Le mot de passe doit contenir au moins 8 caractères.');

            return Command::FAILURE;
        }
        if ($io->askHidden('Confirmer le mot de passe') !== $password) {
            $io->error('Les deux mots de passe ne correspondent pas.');

            return Command::FAILURE;
        }

        $hash = $this->passwordHasher->hashPassword(new Admin(), $password);
        $id = $this->connection->fetchOne('SELECT id FROM admins WHERE username = :username', ['username' => $username]);

        if ($id === false) {
            $this->connection->insert('admins', ['username' => $username, 'password' => $hash]);
            $io->success(sprintf('Administrateur « %s » créé.', $username));
        } else {
            $this->connection->update('admins', ['password' => $hash], ['id' => $id]);
            $io->success(sprintf('Mot de passe de « %s » mis à jour.', $username));
        }

        return Command::SUCCESS;
    }
}
