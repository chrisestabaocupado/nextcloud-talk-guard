<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Command;

use OCA\GsiTalkGuard\Service\Evidencias;
use OCA\GsiTalkGuard\Service\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ClavesGenerar extends Command {
	public function __construct(
		private Evidencias $evidencias,
		private Settings $settings,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('gsi-talk-guard:claves:generar')
			->setDescription('Genera el par de claves de evidencias; guarda la pública y muestra la privada UNA vez')
			->addOption('forzar', null, InputOption::VALUE_NONE, 'Reemplazar la clave existente');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->settings->clavePublica() !== null && !$input->getOption('forzar')) {
			$output->writeln('<error>Ya existe una clave pública. Con --forzar se reemplaza, y las evidencias anteriores solo se podrán leer con la clave privada anterior.</error>');
			return 1;
		}
		$privada = $this->evidencias->generarClaves();
		$output->writeln('<info>Clave pública guardada en Nextcloud.</info>');
		$output->writeln('');
		$output->writeln('CLAVE PRIVADA (cópiela ahora; no se vuelve a mostrar ni se guarda en el servidor):');
		$output->writeln('');
		$output->writeln('  ' . $privada);
		$output->writeln('');
		$output->writeln('Guárdela fuera del servidor (por ejemplo, en un gestor de contraseñas de la GSI con acceso dual).');
		$output->writeln('Sin ella, las evidencias no se pueden leer.');
		return 0;
	}
}
